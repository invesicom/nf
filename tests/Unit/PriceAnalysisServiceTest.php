<?php

namespace Tests\Unit;

use App\Models\AsinData;
use App\Services\PriceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PriceAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Get standard mock response for price analysis.
     */
    private function getMockPriceAnalysisResponse(): string
    {
        return json_encode([
            'msrp_analysis' => [
                'estimated_msrp' => '$49.99',
                'msrp_source' => 'Product category average',
                'amazon_price_assessment' => 'Below MSRP',
            ],
            'market_comparison' => [
                'price_positioning' => 'Mid-range',
                'typical_alternatives_range' => '$30-$70',
                'value_proposition' => 'Good value for features offered.',
            ],
            'price_insights' => [
                'seasonal_consideration' => 'Wait for Black Friday.',
                'deal_indicators' => 'Look for 20% off.',
                'caution_flags' => 'Avoid unusually low prices.',
            ],
            'summary' => 'This product is competitively priced.',
        ]);
    }

    /**
     * Mock all LLM provider endpoints.
     */
    private function mockAllLLMProviders(string $content, int $status = 200): void
    {
        Http::fake([
            // OpenAI / DeepSeek style response
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $content]]],
            ], $status),
            // Ollama style response
            '*/api/generate' => Http::response([
                'response' => $content,
            ], $status),
        ]);
    }

    #[Test]
    public function it_analyzes_pricing_for_valid_product(): void
    {
        $this->mockAllLLMProviders($this->getMockPriceAnalysisResponse());

        $asinData = AsinData::factory()->create([
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => 'Test Product',
            'price_analysis_status' => 'pending',
        ]);

        $service = app(PriceAnalysisService::class);
        $result = $service->analyzePricing($asinData);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('msrp_analysis', $result);
        $this->assertArrayHasKey('market_comparison', $result);
        $this->assertArrayHasKey('price_insights', $result);
        $this->assertArrayHasKey('summary', $result);

        $asinData->refresh();
        $this->assertEquals('completed', $asinData->price_analysis_status);
        $this->assertNotNull($asinData->price_analyzed_at);
    }

    #[Test]
    public function it_throws_exception_for_product_without_title(): void
    {
        $asinData = AsinData::factory()->create([
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product title is required');

        $service = app(PriceAnalysisService::class);
        $service->analyzePricing($asinData);
    }

    #[Test]
    public function it_handles_api_failure_gracefully(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response('Server Error', 500),
            '*/api/generate' => Http::response('Server Error', 500),
        ]);

        $asinData = AsinData::factory()->create([
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => 'Test Product',
            'price_analysis_status' => 'pending',
        ]);

        $service = app(PriceAnalysisService::class);

        $this->expectException(\Exception::class);
        $service->analyzePricing($asinData);

        $asinData->refresh();
        $this->assertEquals('failed', $asinData->price_analysis_status);
    }

    #[Test]
    public function it_handles_malformed_json_response(): void
    {
        $this->mockAllLLMProviders('invalid json response');

        $asinData = AsinData::factory()->create([
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => 'Test Product',
            'price_analysis_status' => 'pending',
        ]);

        $service = app(PriceAnalysisService::class);
        $result = $service->analyzePricing($asinData);

        // Should return fallback structure
        $this->assertArrayHasKey('msrp_analysis', $result);
        $this->assertArrayHasKey('analysis_error', $result);
        $this->assertTrue($result['analysis_error']);
    }

    #[Test]
    public function it_checks_service_availability(): void
    {
        $service = app(PriceAnalysisService::class);
        
        // Service should be available if API key is configured
        $this->assertIsBool($service->isAvailable());
    }
}

