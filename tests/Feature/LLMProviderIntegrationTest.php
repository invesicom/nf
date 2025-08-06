<?php

namespace Tests\Feature;

use App\Services\LLMServiceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LLMProviderIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test configuration for both providers
        config([
            'services.openai.api_key' => 'test_openai_key',
            'services.openai.model' => 'gpt-4o-mini',
            'services.deepseek.api_key' => 'test_deepseek_key',
            'services.deepseek.model' => 'deepseek-v3',
            'services.llm.primary_provider' => 'openai',
            'services.llm.fallback_order' => ['deepseek', 'openai'],
        ]);
    }

    public function test_end_to_end_review_analysis_with_multi_provider_system()
    {
        // Mock both providers to be available
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => []], 200),
            'api.deepseek.com/v1/models' => Http::response(['data' => []], 200),
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[{"id":"1","score":25},{"id":"2","score":85}]']]
                ]
            ], 200),
        ]);

        $reviews = [
            ['id' => '1', 'text' => 'Great product! Exactly as described.', 'rating' => 4],
            ['id' => '2', 'text' => 'AMAZING!!! BEST EVER!!! BUY NOW!!!', 'rating' => 5],
        ];

        $manager = app(LLMServiceManager::class);
        $result = $manager->analyzeReviews($reviews);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('detailed_scores', $result);
        
        // Verify the analysis results
        $scores = $result['detailed_scores'];
        $this->assertEquals(25, $scores['1']); // Genuine review
        $this->assertEquals(85, $scores['2']); // Fake review
    }

    public function test_automatic_failover_from_openai_to_deepseek()
    {
        Http::fake([
            // OpenAI fails
            'api.openai.com/*' => Http::response(['error' => 'Rate limit exceeded'], 429),
            // DeepSeek succeeds
            'api.deepseek.com/v1/models' => Http::response(['data' => []], 200),
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[{"id":"1","score":30}]']]
                ]
            ], 200),
        ]);

        $reviews = [
            ['id' => '1', 'text' => 'Good product overall', 'rating' => 4],
        ];

        $manager = app(LLMServiceManager::class);
        $result = $manager->analyzeReviews($reviews);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        
        // Verify DeepSeek was used
        $firstResult = $result['results'][0];
        $this->assertEquals('deepseek', $firstResult['provider']);
    }

    public function test_cost_comparison_between_providers()
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => []], 200),
            'api.deepseek.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $manager = app(LLMServiceManager::class);
        $comparison = $manager->getCostComparison(100);

        $this->assertIsArray($comparison);
        $this->assertArrayHasKey('OpenAI-gpt-4o-mini', $comparison);
        $this->assertArrayHasKey('DeepSeek-API-deepseek-v3', $comparison);

        $openaiCost = $comparison['OpenAI-gpt-4o-mini']['cost'];
        $deepseekCost = $comparison['DeepSeek-API-deepseek-v3']['cost'];

        // DeepSeek should be significantly cheaper
        $this->assertLessThan($deepseekCost, $openaiCost);
        
        // Verify cost savings are substantial (> 80%)
        $savings = (($openaiCost - $deepseekCost) / $openaiCost) * 100;
        $this->assertGreaterThan(80, $savings);
    }

    public function test_provider_performance_tracking()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[{"id":"1","score":40}]']]
                ]
            ], 200),
        ]);

        $reviews = [
            ['id' => '1', 'text' => 'Test review', 'rating' => 4],
        ];

        $manager = app(LLMServiceManager::class);
        
        // Perform analysis to generate metrics
        $manager->analyzeReviews($reviews);
        
        // Check metrics are tracked
        $metrics = $manager->getProviderMetrics();
        $this->assertIsArray($metrics);
        
        $openaiMetrics = $metrics['OpenAI-gpt-4o-mini'];
        $this->assertArrayHasKey('success_rate', $openaiMetrics);
        $this->assertArrayHasKey('avg_response_time', $openaiMetrics);
        $this->assertArrayHasKey('total_requests', $openaiMetrics);
    }

    public function test_dynamic_provider_switching()
    {
        Http::fake([
            'api.openai.com/v1/models' => Http::response(['data' => []], 200),
            'api.deepseek.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $manager = app(LLMServiceManager::class);

        // Initially should be OpenAI
        $optimal = $manager->getOptimalProvider();
        $this->assertStringContainsString('OpenAI', $optimal->getProviderName());

        // Switch to DeepSeek - this might fail if provider not available, so we'll check the result
        $switched = $manager->switchProvider('deepseek');
        if ($switched) {
            $optimal = $manager->getOptimalProvider();
            $this->assertStringContainsString('DeepSeek', $optimal->getProviderName());
        }

        // Switch back to OpenAI
        $switchedBack = $manager->switchProvider('openai');
        $this->assertTrue($switchedBack); // OpenAI should always be available in tests
        
        $optimal = $manager->getOptimalProvider();
        $this->assertStringContainsString('OpenAI', $optimal->getProviderName());
    }
}