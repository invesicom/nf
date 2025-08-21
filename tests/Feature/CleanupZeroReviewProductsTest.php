<?php

namespace Tests\Feature;

use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CleanupZeroReviewProductsTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanup_command_removes_products_with_zero_reviews()
    {
        // Create products with reviews (should be kept)
        $productWithReviews = AsinData::factory()->create([
            'asin'              => 'B0WITHREVIEWS',
            'status'            => 'completed',
            'fake_percentage'   => 25.0,
            'grade'             => 'B',
            'have_product_data' => true,
            'product_title'     => 'Product with Reviews',
            'reviews'           => [['rating' => 5, 'text' => 'Great product']],
        ]);

        // Create products with zero reviews (should be removed)
        $productEmptyArray = AsinData::factory()->create([
            'asin'              => 'B0EMPTYARRAY',
            'status'            => 'completed',
            'fake_percentage'   => 30.0,
            'grade'             => 'C',
            'have_product_data' => true,
            'product_title'     => 'Product Empty Array',
            'reviews'           => [],
        ]);

        $productNullReviews = AsinData::factory()->create([
            'asin'              => 'B0NULLREVIEWS',
            'status'            => 'completed',
            'fake_percentage'   => 15.0,
            'grade'             => 'A',
            'have_product_data' => true,
            'product_title'     => 'Product Null Reviews',
            'reviews'           => null,
        ]);

        $productEmptyString = AsinData::factory()->create([
            'asin'              => 'B0EMPTYSTRING',
            'status'            => 'completed',
            'fake_percentage'   => 20.0,
            'grade'             => 'B',
            'have_product_data' => true,
            'product_title'     => 'Product Empty String',
            'reviews'           => '',
        ]);

        // Verify initial state
        $this->assertEquals(4, AsinData::count());

        // Run the cleanup command with --force to skip confirmation
        Artisan::call('products:cleanup-zero-reviews', ['--force' => true]);

        // Verify results
        $this->assertEquals(1, AsinData::count());
        $this->assertTrue(AsinData::where('asin', 'B0WITHREVIEWS')->exists());
        $this->assertFalse(AsinData::where('asin', 'B0EMPTYARRAY')->exists());
        $this->assertFalse(AsinData::where('asin', 'B0NULLREVIEWS')->exists());
        $this->assertFalse(AsinData::where('asin', 'B0EMPTYSTRING')->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanup_command_dry_run_shows_what_would_be_deleted()
    {
        // Create products with zero reviews
        AsinData::factory()->create([
            'asin'              => 'B0DRYRUN001',
            'status'            => 'completed',
            'fake_percentage'   => 25.0,
            'grade'             => 'B',
            'have_product_data' => true,
            'product_title'     => 'Dry Run Product 1',
            'reviews'           => [],
        ]);

        AsinData::factory()->create([
            'asin'              => 'B0DRYRUN002',
            'status'            => 'completed',
            'fake_percentage'   => 30.0,
            'grade'             => 'C',
            'have_product_data' => true,
            'product_title'     => 'Dry Run Product 2',
            'reviews'           => null,
        ]);

        // Verify initial state
        $this->assertEquals(2, AsinData::count());

        // Run the cleanup command in dry-run mode
        Artisan::call('products:cleanup-zero-reviews', ['--dry-run' => true]);

        // Verify nothing was deleted
        $this->assertEquals(2, AsinData::count());
        $this->assertTrue(AsinData::where('asin', 'B0DRYRUN001')->exists());
        $this->assertTrue(AsinData::where('asin', 'B0DRYRUN002')->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanup_command_handles_no_zero_review_products()
    {
        // Create only products with reviews
        AsinData::factory()->create([
            'asin'              => 'B0VALIDONLY1',
            'status'            => 'completed',
            'fake_percentage'   => 25.0,
            'grade'             => 'B',
            'have_product_data' => true,
            'product_title'     => 'Valid Product 1',
            'reviews'           => [['rating' => 5, 'text' => 'Great']],
        ]);

        AsinData::factory()->create([
            'asin'              => 'B0VALIDONLY2',
            'status'            => 'completed',
            'fake_percentage'   => 30.0,
            'grade'             => 'C',
            'have_product_data' => true,
            'product_title'     => 'Valid Product 2',
            'reviews'           => [['rating' => 4, 'text' => 'Good']],
        ]);

        // Verify initial state
        $this->assertEquals(2, AsinData::count());

        // Run the cleanup command
        $exitCode = Artisan::call('products:cleanup-zero-reviews', ['--force' => true]);

        // Verify successful exit and no products deleted
        $this->assertEquals(0, $exitCode);
        $this->assertEquals(2, AsinData::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanup_command_preserves_products_with_various_review_formats()
    {
        // Create products with different valid review formats
        $productArrayReviews = AsinData::factory()->create([
            'asin'              => 'B0ARRAYREVS',
            'status'            => 'completed',
            'fake_percentage'   => 25.0,
            'grade'             => 'B',
            'have_product_data' => true,
            'product_title'     => 'Product with Array Reviews',
            'reviews'           => [['rating' => 5, 'text' => 'Great']],
        ]);

        $productJsonStringReviews = AsinData::factory()->create([
            'asin'              => 'B0JSONSTRING',
            'status'            => 'completed',
            'fake_percentage'   => 30.0,
            'grade'             => 'C',
            'have_product_data' => true,
            'product_title'     => 'Product with JSON String Reviews',
            'reviews'           => json_encode([['rating' => 4, 'text' => 'Good']]),
        ]);

        // Create products with zero reviews (should be removed)
        $productZeroReviews = AsinData::factory()->create([
            'asin'              => 'B0ZEROCREATED',
            'status'            => 'completed',
            'fake_percentage'   => 15.0,
            'grade'             => 'A',
            'have_product_data' => true,
            'product_title'     => 'Product Zero Reviews',
            'reviews'           => [],
        ]);

        // Verify initial state
        $this->assertEquals(3, AsinData::count());

        // Run the cleanup command
        Artisan::call('products:cleanup-zero-reviews', ['--force' => true]);

        // Verify only zero-review product was removed
        $this->assertEquals(2, AsinData::count());
        $this->assertTrue(AsinData::where('asin', 'B0ARRAYREVS')->exists());
        $this->assertTrue(AsinData::where('asin', 'B0JSONSTRING')->exists());
        $this->assertFalse(AsinData::where('asin', 'B0ZEROCREATED')->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cleanup_command_shows_progress_bar_for_large_dataset()
    {
        // Create a moderate number of zero-review products to test progress reporting
        for ($i = 1; $i <= 25; $i++) {
            AsinData::factory()->create([
                'asin'              => sprintf('B0PROGRESS%02d', $i),
                'status'            => 'completed',
                'fake_percentage'   => 25.0,
                'grade'             => 'B',
                'have_product_data' => true,
                'product_title'     => "Progress Product {$i}",
                'reviews'           => [],
            ]);
        }

        // Verify initial state
        $this->assertEquals(25, AsinData::count());

        // Run the cleanup command
        $exitCode = Artisan::call('products:cleanup-zero-reviews', ['--force' => true]);

        // Verify all products were deleted
        $this->assertEquals(0, $exitCode);
        $this->assertEquals(0, AsinData::count());
    }
}
