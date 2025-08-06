<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillTotalReviewCounts;
use App\Models\AsinData;
use App\Services\LoggingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillTotalReviewCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock external services to prevent real HTTP requests
        Http::preventStrayRequests();
        
        // Mock LoggingService to prevent actual logging during tests
        $this->mock(LoggingService::class, function ($mock) {
            $mock->shouldReceive('log')->andReturn(true);
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_shows_help_information()
    {
        $this->artisan('reviews:backfill-totals --help')
            ->expectsOutputToContain('Backfill total_reviews_on_amazon for existing analyzed products by scraping Amazon product pages')
            ->expectsOutputToContain('--dry-run')
            ->expectsOutputToContain('--limit')
            ->expectsOutputToContain('--delay')
            ->expectsOutputToContain('--force')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_shows_no_products_when_none_need_backfilling()
    {
        // Create products that already have total_reviews_on_amazon
        AsinData::factory()->count(3)->create([
            'status' => 'completed',
            'total_reviews_on_amazon' => 100,
        ]);

        $this->artisan('reviews:backfill-totals')
            ->expectsOutput('✅ No products found that need total review count backfill.')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_finds_products_needing_backfill()
    {
        // Create products without total_reviews_on_amazon
        $product1 = AsinData::factory()->create([
            'asin' => 'B0TESTPROD1',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
            'product_title' => 'Test Product 1',
        ]);

        $product2 = AsinData::factory()->create([
            'asin' => 'B0TESTPROD2',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
            'product_title' => 'Test Product 2',
        ]);

        // Create a product that shouldn't be included (wrong status)
        AsinData::factory()->create([
            'status' => 'processing',
            'total_reviews_on_amazon' => null,
        ]);

        $this->artisan('reviews:backfill-totals --dry-run')
            ->expectsOutput('📊 Found 2 products to process')
            ->expectsOutput('🧪 Dry run complete. Use without --dry-run to process these products.')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_respects_limit_option()
    {
        // Create 5 products needing backfill
        AsinData::factory()->count(5)->create([
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
        ]);

        $this->artisan('reviews:backfill-totals --dry-run --limit=3')
            ->expectsOutput('📊 Found 3 products to process')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_processes_products_with_force_option()
    {
        // Create products that already have total_reviews_on_amazon
        AsinData::factory()->count(2)->create([
            'status' => 'completed',
            'total_reviews_on_amazon' => 500,
        ]);

        $this->artisan('reviews:backfill-totals --dry-run --force')
            ->expectsOutput('📊 Found 2 products to process (including those with existing data)')
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_successfully_extracts_total_review_counts()
    {
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTPROD1',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
            'product_title' => 'Test Product',
        ]);

        // Mock successful Amazon response with review count
        Http::fake([
            'amazon.com/*' => Http::response($this->getMockAmazonHtml(1401), 200),
        ]);

        $this->artisan('reviews:backfill-totals', ['--limit' => 1, '--no-interaction' => true])
            ->expectsOutput('🚀 Starting to process products...')
            ->expectsOutput('✅ Found 1401 total reviews for B0TESTPROD1')
            ->expectsOutput('🎉 Successfully backfilled total review counts for 1 products!')
            ->assertExitCode(0);

        // Verify database was updated
        $product->refresh();
        $this->assertEquals(1401, $product->total_reviews_on_amazon);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_handles_amazon_http_errors_gracefully()
    {
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTPROD1',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
        ]);

        // Mock 404 response from Amazon
        Http::fake([
            'amazon.com/*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('reviews:backfill-totals', ['--limit' => 1, '--no-interaction' => true])
            ->expectsOutputToContain('❌ Failed')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);

        // Verify database was not updated
        $product->refresh();
        $this->assertNull($product->total_reviews_on_amazon);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_handles_invalid_html_gracefully()
    {
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTPROD1',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
        ]);

        // Mock Amazon response without review count data
        Http::fake([
            'amazon.com/*' => Http::response('<html><body>No review data here</body></html>', 200),
        ]);

        $this->artisan('reviews:backfill-totals', ['--limit' => 1, '--no-interaction' => true])
            ->expectsOutput('⚠️  Could not extract total review count for B0TESTPROD1')
            ->assertExitCode(0);

        // Verify database was not updated
        $product->refresh();
        $this->assertNull($product->total_reviews_on_amazon);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_handles_oversized_responses()
    {
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTPROD1',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
        ]);

        // Mock huge response (>2MB)
        $largeHtml = str_repeat('<div>Large content</div>', 200000); // ~3MB
        Http::fake([
            'amazon.com/*' => Http::response($largeHtml, 200),
        ]);

        $this->artisan('reviews:backfill-totals', ['--limit' => 1, '--no-interaction' => true])
            ->expectsOutputToContain('❌ Failed')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);

        // Verify database was not updated
        $product->refresh();
        $this->assertNull($product->total_reviews_on_amazon);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_respects_delay_option()
    {
        AsinData::factory()->count(2)->create([
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
        ]);

        Http::fake([
            'amazon.com/*' => Http::response($this->getMockAmazonHtml(100), 200),
        ]);

        $startTime = microtime(true);
        
        $this->artisan('reviews:backfill-totals', ['--limit' => 2, '--delay' => 1, '--no-interaction' => true])
            ->assertExitCode(0);

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Should take at least 1 second due to delay between requests
        $this->assertGreaterThan(1.0, $executionTime);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_uses_optimized_http_configuration()
    {
        $product = AsinData::factory()->create([
            'asin' => 'B0TESTPROD1',
            'status' => 'completed',
            'total_reviews_on_amazon' => null,
        ]);

        Http::fake([
            'amazon.com/*' => function ($request) {
                // Verify optimized headers are being used
                $this->assertEquals('gzip, deflate', $request->header('Accept-Encoding')[0]);
                $this->assertEquals('close', $request->header('Connection')[0]);
                $this->assertEquals('no-cache', $request->header('Cache-Control')[0]);
                
                return Http::response($this->getMockAmazonHtml(123), 200);
            },
        ]);

        $this->artisan('reviews:backfill-totals', ['--limit' => 1, '--no-interaction' => true])
            ->assertExitCode(0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function command_extracts_review_counts_from_different_selectors()
    {
        $testCases = [
            'data-hook' => '<span data-hook="total-review-count">1,234 global ratings</span>',
            'cr-pivot' => '<div class="cr-pivot-review-count-info"><span class="totalReviewCount">2,345 reviews</span></div>',
            'text-search' => '<div>Customer Reviews: 3,456 ratings and reviews</div>',
        ];

        foreach ($testCases as $testName => $html) {
            $product = AsinData::factory()->create([
                'asin' => "B0TEST{$testName}",
                'status' => 'completed',
                'total_reviews_on_amazon' => null,
            ]);

            Http::fake([
                'amazon.com/*' => Http::response("<html><body>{$html}</body></html>", 200),
            ]);

            $this->artisan('reviews:backfill-totals', ['--limit' => 1, '--no-interaction' => true]);

            $product->refresh();
            $this->assertNotNull($product->total_reviews_on_amazon, "Failed for test case: {$testName}");
            $this->assertGreaterThan(0, $product->total_reviews_on_amazon, "Invalid count for test case: {$testName}");
        }
    }

    /**
     * Generate mock Amazon product page HTML with review count
     */
    private function getMockAmazonHtml(int $reviewCount): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><title>Amazon Product Page</title></head>
<body>
    <div id="dp-container">
        <div class="product-info">
            <span data-hook="total-review-count">{$reviewCount} global ratings</span>
        </div>
        <div id="reviewsMedley">
            <div class="cr-pivot-review-count-info">
                Customer reviews and ratings: {$reviewCount} total
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }
}