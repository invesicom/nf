<?php

namespace Tests\Feature;

use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BatchJobTimestampPreservationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function batch_reanalysis_preserves_original_analysis_timestamps()
    {
        // Create a product with original analysis timestamps
        $originalAnalysisDate = now()->subDays(7);
        $product = AsinData::factory()->create([
            'asin'            => 'B0TESTBATCH1',
            'status'          => 'completed',
            'fake_percentage' => 80.0,
            'grade'           => 'F',
            'reviews'         => [
                ['rating' => 5, 'text' => 'Great product', 'meta_data' => ['verified_purchase' => true]],
                ['rating' => 4, 'text' => 'Good quality', 'meta_data' => ['verified_purchase' => false]],
            ],
            'openai_result'     => ['detailed_scores' => [0 => 20, 1 => 85]],
            'first_analyzed_at' => $originalAnalysisDate,
            'last_analyzed_at'  => $originalAnalysisDate,
        ]);

        // Record the original timestamps
        $originalFirstAnalyzed = $product->first_analyzed_at;
        $originalLastAnalyzed = $product->last_analyzed_at;
        $originalUpdatedAt = $product->updated_at;

        // Wait a moment to ensure timestamps would be different
        sleep(1);

        // Run the batch reanalysis command
        $this->artisan('reanalyze:graded-products', [
            '--fast'   => true,
            '--grades' => 'F',
            '--limit'  => 1,
            '--force'  => true,
        ])->assertExitCode(0);

        // Refresh the product from database
        $product->refresh();

        // Assert that analysis timestamps are preserved (golden rule)
        $this->assertEquals(
            $originalFirstAnalyzed,
            $product->first_analyzed_at,
            'first_analyzed_at should never change during batch operations'
        );
        $this->assertEquals(
            $originalLastAnalyzed,
            $product->last_analyzed_at,
            'last_analyzed_at should only change for user-initiated re-analysis'
        );

        // Assert that data was actually updated
        $this->assertNotEquals(
            80.0,
            $product->fake_percentage,
            'fake_percentage should be updated by batch reanalysis'
        );
        $this->assertNotEquals(
            'F',
            $product->grade,
            'grade should be updated by batch reanalysis'
        );

        // Assert that updated_at changed (Laravel's automatic behavior)
        $this->assertNotEquals(
            $originalUpdatedAt,
            $product->updated_at,
            'updated_at should change when data is modified'
        );

        // Assert that analysis_notes were added
        $this->assertStringContainsString('Fast reanalysis applied', $product->analysis_notes);
    }

    #[Test]
    public function ui_shows_original_analysis_date_after_batch_operations()
    {
        // Create a product analyzed 5 days ago
        $originalAnalysisDate = now()->subDays(5);
        $product = AsinData::factory()->create([
            'asin'              => 'B0TESTUI001',
            'status'            => 'completed',
            'fake_percentage'   => 75.0,
            'grade'             => 'F',
            'reviews'           => [['rating' => 5, 'text' => 'Test review', 'meta_data' => ['verified_purchase' => true]]],
            'openai_result'     => ['detailed_scores' => [0 => 85]],
            'first_analyzed_at' => $originalAnalysisDate,
            'last_analyzed_at'  => $originalAnalysisDate,
        ]);

        // Run batch operation
        $this->artisan('reanalyze:graded-products', [
            '--fast'   => true,
            '--grades' => 'F',
            '--limit'  => 1,
            '--force'  => true,
        ]);

        // Check what the UI would display
        $product->refresh();

        // UI logic from the blade template
        $displayTimestamp = ($product->first_analyzed_at ?? $product->updated_at);
        $isReanalyzed = $product->last_analyzed_at && $product->first_analyzed_at &&
                       $product->last_analyzed_at->ne($product->first_analyzed_at);

        // Should show original analysis date, not current time (use format to avoid microsecond precision issues)
        $this->assertEquals($originalAnalysisDate->format('Y-m-d H:i:s'), $displayTimestamp->format('Y-m-d H:i:s'));
        $this->assertFalse($isReanalyzed, 'Should not show as re-analyzed after batch operation');

        // Verify the display text would be about the original date
        $daysDiff = $displayTimestamp->diffInDays(now());
        $this->assertEquals(5, round($daysDiff), 'UI should show original analysis date (5 days ago)');
    }

    #[Test]
    public function multiple_batch_operations_preserve_original_timestamps()
    {
        // Create a product
        $originalDate = now()->subWeeks(2);
        $product = AsinData::factory()->create([
            'asin'              => 'B0TESTMULTI1',
            'status'            => 'completed',
            'fake_percentage'   => 90.0,
            'grade'             => 'F',
            'reviews'           => [['rating' => 5, 'text' => 'Test', 'meta_data' => ['verified_purchase' => true]]],
            'openai_result'     => ['detailed_scores' => [0 => 95]],
            'first_analyzed_at' => $originalDate,
            'last_analyzed_at'  => $originalDate,
        ]);

        $originalFirstAnalyzed = $product->first_analyzed_at;
        $originalLastAnalyzed = $product->last_analyzed_at;

        // Run batch operation multiple times
        for ($i = 0; $i < 3; $i++) {
            sleep(1); // Ensure different updated_at times

            $this->artisan('reanalyze:graded-products', [
                '--fast'   => true,
                '--grades' => 'F,D,C', // Include all grades the product might have
                '--limit'  => 10,
                '--force'  => true,
            ]);
        }

        $product->refresh();

        // Analysis timestamps should be completely unchanged
        $this->assertEquals($originalFirstAnalyzed, $product->first_analyzed_at);
        $this->assertEquals($originalLastAnalyzed, $product->last_analyzed_at);

        // But updated_at should have changed
        $this->assertNotEquals($originalDate, $product->updated_at);
    }

    #[Test]
    public function batch_operation_updates_data_but_preserves_display_order()
    {
        // Capture expected timestamps to avoid timing issues
        $baseTime = now();
        $olderExpectedTimestamp = $baseTime->copy()->subDays(10);
        $newerExpectedTimestamp = $baseTime->copy()->subDays(5);

        // Create two products with different original analysis dates
        $olderProduct = AsinData::factory()->create([
            'asin'              => 'B0TESTORDER1',
            'status'            => 'completed',
            'fake_percentage'   => 85.0,
            'grade'             => 'F',
            'have_product_data' => true,
            'product_title'     => 'Test Product 1',
            'reviews'           => [['rating' => 5, 'text' => 'Test', 'meta_data' => ['verified_purchase' => true]]],
            'openai_result'     => ['detailed_scores' => [0 => 90]],
            'first_analyzed_at' => $olderExpectedTimestamp,
            'last_analyzed_at'  => $olderExpectedTimestamp,
        ]);

        sleep(1);

        $newerProduct = AsinData::factory()->create([
            'asin'              => 'B0TESTORDER2',
            'status'            => 'completed',
            'fake_percentage'   => 80.0,
            'grade'             => 'F',
            'have_product_data' => true,
            'product_title'     => 'Test Product 2',
            'reviews'           => [['rating' => 5, 'text' => 'Test', 'meta_data' => ['verified_purchase' => true]]],
            'openai_result'     => ['detailed_scores' => [0 => 85]],
            'first_analyzed_at' => $newerExpectedTimestamp,
            'last_analyzed_at'  => $newerExpectedTimestamp,
        ]);

        // Run batch reanalysis on both
        $this->artisan('reanalyze:graded-products', [
            '--fast'   => true,
            '--grades' => 'F',
            '--limit'  => 10,
            '--force'  => true,
        ]);

        // Check display order using the SAME query as the controller
        $productsOrderedByAnalysisDate = AsinData::where('status', 'completed')
            ->whereNotNull('fake_percentage')
            ->whereNotNull('grade')
            ->where('have_product_data', true)
            ->whereNotNull('product_title')
            ->whereNotNull('reviews')
            ->where('reviews', '!=', '[]')
            ->whereIn('asin', ['B0TESTORDER1', 'B0TESTORDER2'])
            ->orderBy('first_analyzed_at', 'desc')
            ->get();

        // Newer product should still appear first in "recently analyzed" list
        $this->assertEquals('B0TESTORDER2', $productsOrderedByAnalysisDate->first()->asin);
        $this->assertEquals('B0TESTORDER1', $productsOrderedByAnalysisDate->last()->asin);

        // Verify that updated_at changed but first_analyzed_at didn't
        $olderProduct->refresh();
        $newerProduct->refresh();

        $this->assertEquals($olderExpectedTimestamp->format('Y-m-d H:i'), $olderProduct->first_analyzed_at->format('Y-m-d H:i'));
        $this->assertEquals($newerExpectedTimestamp->format('Y-m-d H:i'), $newerProduct->first_analyzed_at->format('Y-m-d H:i'));
        $this->assertTrue($olderProduct->updated_at->greaterThan(now()->subMinutes(1)));
        $this->assertTrue($newerProduct->updated_at->greaterThan(now()->subMinutes(1)));
    }

    #[Test]
    public function golden_rule_documentation_in_code()
    {
        // Verify that the golden rule is documented in the reanalysis command
        $commandFile = file_get_contents(app_path('Console/Commands/ReanalyzeGradedProducts.php'));

        $this->assertStringContainsString(
            'Do NOT update first_analyzed_at or last_analyzed_at',
            $commandFile,
            'Golden rule should be documented in the reanalysis command'
        );
        $this->assertStringContainsString(
            'preserve display order',
            $commandFile,
            'The reason for the golden rule should be documented'
        );
    }
}
