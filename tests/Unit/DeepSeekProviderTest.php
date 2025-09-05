<?php

namespace Tests\Unit;

use App\Services\Providers\DeepSeekProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepSeekProviderTest extends TestCase
{
    private DeepSeekProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.deepseek.api_key'  => 'test_deepseek_key',
            'services.deepseek.base_url' => 'https://api.deepseek.com/v1',
            'services.deepseek.model'    => 'deepseek-v3',
        ]);

        $this->provider = new DeepSeekProvider();
    }

    public function test_analyzes_reviews_successfully()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Amazing product! Fast shipping!', 'rating' => 5],
            ['id' => 2, 'text' => 'Terrible quality. Broke immediately.', 'rating' => 1],
        ];

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '[{"id":"1","score":25},{"id":"2","score":75}]']],
                ],
            ], 200),
        ]);

        $result = $this->provider->analyzeReviews($reviews);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('detailed_scores', $result);
        $this->assertArrayHasKey('analysis_provider', $result);
        $this->assertArrayHasKey('total_cost', $result);
        $this->assertCount(2, $result['detailed_scores']);

        $firstResult = $result['detailed_scores']['1'];
        $this->assertEquals(25, $firstResult['score']);
        $this->assertEquals('genuine', $firstResult['label']);
        $this->assertArrayHasKey('confidence', $firstResult);
        $this->assertArrayHasKey('explanation', $firstResult);
    }

    public function test_handles_api_errors_gracefully()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Test review', 'rating' => 4],
        ];

        Http::fake([
            'api.deepseek.com/*' => Http::response(['error' => 'Rate limit exceeded'], 429),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DeepSeek API error: 429');

        $this->provider->analyzeReviews($reviews);
    }

    public function test_returns_empty_results_for_empty_reviews()
    {
        $result = $this->provider->analyzeReviews([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('results', $result);
        $this->assertEmpty($result['results']);
    }

    public function test_calculates_optimized_max_tokens()
    {
        $tokens = $this->provider->getOptimizedMaxTokens(10);

        $this->assertIsInt($tokens);
        $this->assertGreaterThan(0, $tokens);

        // Should be reasonable for small review sets (increased due to JSON response requirements)
        $this->assertLessThan(5000, $tokens); // Increased from 500 to accommodate larger JSON responses
    }

    public function test_checks_availability_with_api_key()
    {
        Http::fake([
            'api.deepseek.com/v1/models' => Http::response(['data' => []], 200),
        ]);

        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_checks_availability_without_api_key()
    {
        config(['services.deepseek.api_key' => '']);
        $provider = new DeepSeekProvider();

        $this->assertFalse($provider->isAvailable());
    }

    public function test_detects_local_deployment()
    {
        config(['services.deepseek.base_url' => 'http://localhost:8000/v1']);
        $provider = new DeepSeekProvider();

        $this->assertStringContainsString('Self-Hosted', $provider->getProviderName());
    }

    public function test_calculates_api_cost()
    {
        $cost = $this->provider->getEstimatedCost(100);

        $this->assertIsFloat($cost);
        $this->assertGreaterThan(0, $cost);

        // Should be significantly cheaper than OpenAI (less than $0.10 for 100 reviews)
        $this->assertLessThan(0.10, $cost);
    }

    public function test_returns_zero_cost_for_local_deployment()
    {
        config(['services.deepseek.base_url' => 'http://localhost:8000/v1']);
        $provider = new DeepSeekProvider();

        $cost = $provider->getEstimatedCost(100);
        $this->assertEquals(0.0, $cost);
    }

    public function test_handles_malformed_json_response()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Test review', 'rating' => 4],
        ];

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'invalid json response']],
                ],
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to parse DeepSeek response');

        $this->provider->analyzeReviews($reviews);
    }
}
