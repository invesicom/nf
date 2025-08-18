<?php

namespace Tests\Feature;

use App\Models\AsinData;
use App\Services\ReviewAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisTimestampBehaviorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function new_analysis_sets_both_timestamps()
    {
        // Create a product without analysis timestamps
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTNEW001',
            'status' => 'pending',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great product', 'meta_data' => ['verified_purchase' => true]],
                ['rating' => 4, 'text' => 'Good quality', 'meta_data' => ['verified_purchase' => false]],
            ],
            'first_analyzed_at' => null,
            'last_analyzed_at' => null,
        ]);

        $this->assertNull($product->first_analyzed_at);
        $this->assertNull($product->last_analyzed_at);

        // Perform analysis
        $reviewAnalysisService = app(ReviewAnalysisService::class);
        $analyzedProduct = $reviewAnalysisService->analyzeWithOpenAI($product);

        $analyzedProduct->refresh();

        // Both timestamps should be set to the same value
        $this->assertNotNull($analyzedProduct->first_analyzed_at);
        $this->assertNotNull($analyzedProduct->last_analyzed_at);
        $this->assertEquals($analyzedProduct->first_analyzed_at, $analyzedProduct->last_analyzed_at);
        $this->assertEquals('completed', $analyzedProduct->status);
    }

    #[Test]
    public function existing_analysis_preserves_first_analyzed_at()
    {
        // Create a product that already has first_analyzed_at
        $originalFirstAnalyzed = now()->subDays(3);
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTEXIST1',
            'status' => 'completed',
            'reviews' => [['rating' => 5, 'text' => 'Test', 'meta_data' => ['verified_purchase' => true]]],
            'openai_result' => null, // Missing analysis
            'first_analyzed_at' => $originalFirstAnalyzed,
            'last_analyzed_at' => $originalFirstAnalyzed,
        ]);

        // Re-analyze (simulating missing OpenAI result completion)
        $reviewAnalysisService = app(ReviewAnalysisService::class);
        $analyzedProduct = $reviewAnalysisService->analyzeWithOpenAI($product);

        $analyzedProduct->refresh();

        // first_analyzed_at should be preserved, last_analyzed_at should be updated
        $this->assertEquals($originalFirstAnalyzed, $analyzedProduct->first_analyzed_at);
        $this->assertNotEquals($originalFirstAnalyzed, $analyzedProduct->last_analyzed_at);
        $this->assertTrue($analyzedProduct->last_analyzed_at->greaterThan($originalFirstAnalyzed));
    }

    #[Test]
    public function ui_display_logic_works_correctly()
    {
        // Test case 1: Product never re-analyzed (both timestamps same)
        $analysisDate = now()->subDays(2);
        $product1 = AsinData::factory()->create([
            'first_analyzed_at' => $analysisDate,
            'last_analyzed_at' => $analysisDate,
        ]);

        // UI should show "Analyzed X ago"
        $isReanalyzed = $product1->last_analyzed_at && $product1->first_analyzed_at && 
                       $product1->last_analyzed_at->ne($product1->first_analyzed_at);
        $this->assertFalse($isReanalyzed);

        // Test case 2: Product re-analyzed (timestamps different)
        $product2 = AsinData::factory()->create([
            'first_analyzed_at' => now()->subDays(5),
            'last_analyzed_at' => now()->subHours(2),
        ]);

        // UI should show "Re-analyzed X ago"
        $isReanalyzed = $product2->last_analyzed_at && $product2->first_analyzed_at && 
                       $product2->last_analyzed_at->ne($product2->first_analyzed_at);
        $this->assertTrue($isReanalyzed);

        // Test case 3: Fallback to updated_at when analysis timestamps missing
        $product3 = AsinData::factory()->create([
            'first_analyzed_at' => null,
            'last_analyzed_at' => null,
        ]);

        $displayTimestamp = ($product3->first_analyzed_at ?? $product3->updated_at);
        $this->assertEquals($product3->updated_at, $displayTimestamp);
    }

    #[Test]
    public function future_reanalyze_button_behavior()
    {
        // Simulate what the future "Re-analyze" button should do
        $originalAnalysisDate = now()->subWeeks(1);
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTFUTURE1',
            'status' => 'completed',
            'fake_percentage' => 50.0,
            'grade' => 'C',
            'first_analyzed_at' => $originalAnalysisDate,
            'last_analyzed_at' => $originalAnalysisDate,
        ]);

        // Simulate user clicking "Re-analyze" button
        // This should update last_analyzed_at but preserve first_analyzed_at
        $product->update([
            'fake_percentage' => 30.0,
            'grade' => 'B',
            'last_analyzed_at' => now(), // Only update this timestamp
        ]);

        $product->refresh();

        // Verify behavior
        $this->assertEquals($originalAnalysisDate, $product->first_analyzed_at);
        $this->assertNotEquals($originalAnalysisDate, $product->last_analyzed_at);
        
        // UI should now show "Re-analyzed X ago"
        $isReanalyzed = $product->last_analyzed_at && $product->first_analyzed_at && 
                       $product->last_analyzed_at->ne($product->first_analyzed_at);
        $this->assertTrue($isReanalyzed);
    }
}
