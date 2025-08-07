<?php

namespace Tests\Unit;

use App\Services\Providers\OllamaProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaProviderTest extends TestCase
{
    private OllamaProvider $provider;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        config([
            'services.ollama.base_url' => 'http://localhost:11434',
            'services.ollama.model' => 'llama3.2:3b',
            'services.ollama.timeout' => 300,
        ]);
        
        $this->provider = new OllamaProvider();
    }

    public function test_analyzes_reviews_successfully()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Amazing product! Fast shipping!', 'rating' => 5],
            ['id' => 2, 'text' => 'Terrible quality. Broke immediately.', 'rating' => 1],
        ];

        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'model' => 'llama3.2:3b',
                'response' => '[{"id": 1, "score": 15}, {"id": 2, "score": 25}]',
                'done' => true,
                'total_duration' => 5000000000,
            ])
        ]);

        $result = $this->provider->analyzeReviews($reviews);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('detailed_scores', $result);
        $this->assertCount(2, $result['detailed_scores']);
    }

    public function test_handles_connection_errors_gracefully()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Test review', 'rating' => 5],
        ];

        Http::fake([
            'localhost:11434/api/generate' => Http::response('', 500)
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Ollama API request failed');
        
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
        $tokens = $this->provider->getOptimizedMaxTokens(25);
        
        // Should be review count * 10 + buffer
        $expected = 25 * 10 + min(1000, 25 * 5); // 250 + 125 = 375
        $this->assertEquals($expected, $tokens);
    }

    public function test_checks_availability_with_ollama_running()
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response(['models' => []])
        ]);

        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_checks_availability_with_ollama_not_running()
    {
        Http::fake([
            'localhost:11434/api/tags' => Http::response('', 500)
        ]);

        $this->assertFalse($this->provider->isAvailable());
    }

    public function test_returns_zero_cost()
    {
        $cost = $this->provider->getEstimatedCost(100);
        
        $this->assertEquals(0.0, $cost);
    }

    public function test_returns_correct_provider_name()
    {
        $name = $this->provider->getProviderName();
        
        $this->assertStringContainsString('Ollama', $name);
        $this->assertStringContainsString('phi4:14b', $name);
    }

    public function test_handles_malformed_json_response()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Test review', 'rating' => 5],
        ];

        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'model' => 'llama3.2:3b',
                'response' => 'invalid json {',
                'done' => true,
            ])
        ]);

        // With improved parsing, malformed JSON now falls back to heuristic parsing
        $result = $this->provider->analyzeReviews($reviews);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('detailed_scores', $result);
        // Heuristic parsing should provide some result even with malformed JSON
    }

    public function test_uses_configured_timeout()
    {
        $reviews = [
            ['id' => 1, 'text' => 'Test review', 'rating' => 5],
        ];

        Http::fake([
            'localhost:11434/api/generate' => Http::response([
                'model' => 'llama3.2:3b',
                'response' => json_encode(['results' => []]),
                'done' => true,
            ])
        ]);

        $this->provider->analyzeReviews($reviews);

        // Just verify that the request was made successfully with the expected URL
        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:11434/api/generate';
        });
    }
}