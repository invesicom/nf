<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\MetricsCalculationService;
use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EnhancedExplanationGenerationTest extends TestCase
{
    use RefreshDatabase;

    private MetricsCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MetricsCalculationService();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_multi_paragraph_explanations_for_excellent_products()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 20, ['text' => 'Great product!', 'rating' => 5])
        ]);

        // Set up openai_result with excellent authenticity (5% fake)
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 5,
                'confidence' => 'high',
                'fake_examples' => [],
                'key_patterns' => []
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        // Should contain multiple paragraphs separated by \n\n
        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs), 'Should have at least 3 paragraphs');

        // Check content for excellent products
        $this->assertStringContainsString('excellent review authenticity', $explanation);
        $this->assertStringContainsString('genuine customer experiences', $explanation);
        $this->assertStringContainsString('trustworthy product', $explanation);
        $this->assertStringContainsString('authentic customer feedback', $explanation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_multi_paragraph_explanations_for_good_products()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 15, ['text' => 'Good product', 'rating' => 4])
        ]);

        // Set up openai_result with good authenticity (25% fake)
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 25,
                'confidence' => 'medium',
                'fake_examples' => [],
                'key_patterns' => []
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs), 'Should have at least 3 paragraphs');

        // Check content for good products
        $this->assertStringContainsString('good review authenticity', $explanation);
        $this->assertStringContainsString('low fake review activity', $explanation);
        $this->assertStringContainsString('reliable for purchase decisions', $explanation);
        $this->assertStringContainsString('predominantly authentic', $explanation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_multi_paragraph_explanations_for_moderate_products()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 12, ['text' => 'Okay product', 'rating' => 3])
        ]);

        // Set up openai_result with moderate concerns (40% fake)
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 40,
                'confidence' => 'medium',
                'fake_examples' => [],
                'key_patterns' => []
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs), 'Should have at least 3 paragraphs');

        // Check content for moderate products
        $this->assertStringContainsString('moderate fake review concerns', $explanation);
        $this->assertStringContainsString('careful evaluation', $explanation);
        $this->assertStringContainsString('exercise caution', $explanation);
        $this->assertStringContainsString('artificial inflation', $explanation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_multi_paragraph_explanations_for_high_risk_products()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 10, ['text' => 'Amazing!', 'rating' => 5])
        ]);

        // Set up openai_result with high fake activity (65% fake)
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 65,
                'confidence' => 'high',
                'fake_examples' => [],
                'key_patterns' => []
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs), 'Should have at least 3 paragraphs');

        // Check content for high risk products
        $this->assertStringContainsString('high fake review activity', $explanation);
        $this->assertStringContainsString('significant authenticity concerns', $explanation);
        $this->assertStringContainsString('Proceed with caution', $explanation);
        $this->assertStringContainsString('coordinated efforts', $explanation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_multi_paragraph_explanations_for_very_high_risk_products()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 8, ['text' => 'Perfect!', 'rating' => 5])
        ]);

        // Set up openai_result with very high fake activity (85% fake)
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 85,
                'confidence' => 'high',
                'fake_examples' => [],
                'key_patterns' => []
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs), 'Should have at least 3 paragraphs');

        // Check content for very high risk products
        $this->assertStringContainsString('very high fake review activity', $explanation);
        $this->assertStringContainsString('artificially generated', $explanation);
        $this->assertStringContainsString('coordinated fake review campaigns', $explanation);
        $this->assertStringContainsString('alternative products', $explanation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_preserves_llm_explanation_when_available()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 10, ['text' => 'Good product', 'rating' => 4])
        ]);

        $llmExplanation = "LLM generated first paragraph.\n\nLLM generated second paragraph.\n\nLLM generated third paragraph.";

        // Set up openai_result with LLM explanation
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 30,
                'confidence' => 'medium',
                'explanation' => $llmExplanation,
                'fake_examples' => [],
                'key_patterns' => []
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        // Should use the LLM explanation
        $this->assertStringContainsString('LLM generated first paragraph', $explanation);
        $this->assertStringContainsString('LLM generated second paragraph', $explanation);
        $this->assertStringContainsString('LLM generated third paragraph', $explanation);

        // Should maintain paragraph structure
        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_adds_key_patterns_to_explanation_when_available()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 10, ['text' => 'Test review', 'rating' => 4])
        ]);

        // Set up openai_result with key patterns
        $asinData->update([
            'openai_result' => [
                'fake_percentage' => 20,
                'confidence' => 'medium',
                'fake_examples' => [],
                'key_patterns' => ['Generic praise', 'Short reviews', 'Timing clusters']
            ]
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        // Should include key patterns in explanation
        $this->assertStringContainsString('Key patterns identified', $explanation);
        $this->assertStringContainsString('Generic praise', $explanation);
        $this->assertStringContainsString('Short reviews', $explanation);
        $this->assertStringContainsString('Timing clusters', $explanation);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_empty_or_null_aggregate_data()
    {
        $asinData = AsinData::factory()->create([
            'reviews' => array_fill(0, 5, ['text' => 'Review', 'rating' => 3])
        ]);

        // Test with empty openai_result - should generate fallback explanation
        $result = $this->service->calculateFinalMetrics($asinData);

        $explanation = $asinData->fresh()->explanation;

        // Should generate fallback explanation with paragraph structure
        $this->assertNotNull($explanation);
        $paragraphs = explode("\n\n", $explanation);
        $this->assertGreaterThanOrEqual(3, count($paragraphs));
    }
}
