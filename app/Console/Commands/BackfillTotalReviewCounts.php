<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LoggingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

class BackfillTotalReviewCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reviews:backfill-totals
                            {--dry-run : Show what would be processed without making changes}
                            {--limit=50 : Maximum number of products to process in one run}
                            {--delay=2 : Delay in seconds between requests to avoid rate limiting}
                            {--force : Process all products even if they already have total_reviews_on_amazon}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill total_reviews_on_amazon for existing analyzed products by scraping Amazon product pages';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Starting total review count backfill process...');
        $this->newLine();

        // Get options
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('delay');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('🧪 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Build query based on conditions
        $query = AsinData::where('status', 'completed');

        if (!$force) {
            $query->whereNull('total_reviews_on_amazon');
        }

        $products = $query->orderBy('updated_at', 'desc')
                         ->limit($limit)
                         ->get();

        if ($products->isEmpty()) {
            $this->info('✅ No products found that need total review count backfill.');

            return self::SUCCESS;
        }

        $this->info("📊 Found {$products->count()} products to process".($force ? ' (including those with existing data)' : ''));
        $this->newLine();

        // Show summary
        $this->table(
            ['ASIN', 'Product Title', 'Current Total', 'Reviews Analyzed', 'Updated'],
            $products->map(function ($product) {
                return [
                    $product->asin,
                    \Str::limit($product->product_title ?? 'No title', 40),
                    $product->total_reviews_on_amazon ?? 'NULL',
                    count($product->getReviewsArray()),
                    $product->updated_at->diffForHumans(),
                ];
            })->toArray()
        );

        if ($dryRun) {
            $this->info('🧪 Dry run complete. Use without --dry-run to process these products.');

            return self::SUCCESS;
        }

        if (!$dryRun && !$this->option('no-interaction') && !$this->confirm('Do you want to proceed with processing these products?')) {
            $this->info('❌ Operation cancelled by user.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('🚀 Starting to process products...');
        $this->newLine();

        $processed = 0;
        $success = 0;
        $failed = 0;
        $skipped = 0;

        $progressBar = $this->output->createProgressBar($products->count());
        $progressBar->start();

        foreach ($products as $product) {
            $processed++;

            try {
                // Extract total review count from Amazon product page
                $totalReviews = $this->extractTotalReviewCount($product->asin);

                if ($totalReviews !== null) {
                    $product->update(['total_reviews_on_amazon' => $totalReviews]);
                    $success++;
                    LoggingService::log("Backfilled total review count for ASIN {$product->asin}: {$totalReviews}");
                } else {
                    $skipped++;
                    LoggingService::log("Could not extract total review count for ASIN {$product->asin}");
                }
            } catch (\Exception $e) {
                $failed++;
                LoggingService::log("Failed to process ASIN {$product->asin}: ".$e->getMessage());
                $this->warn("\n⚠️  Failed to process {$product->asin}: ".$e->getMessage());
            }

            $progressBar->advance();

            // Add delay to avoid rate limiting
            if ($delay > 0 && $processed < $products->count()) {
                sleep($delay);
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Show results
        $this->info('📈 Backfill process completed!');
        $this->newLine();

        $this->table(
            ['Result', 'Count'],
            [
                ['✅ Successfully updated', $success],
                ['⚠️  Skipped (no data found)', $skipped],
                ['❌ Failed', $failed],
                ['📊 Total processed', $processed],
            ]
        );

        if ($success > 0) {
            $this->info("🎉 Successfully backfilled total review counts for {$success} products!");
            $this->info('You can now view these products with enhanced review statistics.');
        }

        if ($failed > 0) {
            $this->warn("⚠️  {$failed} products failed to process. Check logs for details.");
        }

        return self::SUCCESS;
    }

    /**
     * Extract total review count from Amazon product page using minimal resources
     * Uses direct HTTP without proxies for lightweight backfill operations.
     */
    private function extractTotalReviewCount(string $asin): ?int
    {
        try {
            $url = "https://www.amazon.com/dp/{$asin}";

            // Use minimal resource configuration - no proxies, optimized headers
            $response = Http::timeout(15) // Reduced timeout for faster failure detection
                          ->withOptions([
                              'verify'          => false, // Skip SSL verification for speed
                              'connect_timeout' => 10, // Quick connection timeout
                              'stream'          => false, // Don't stream large responses
                          ])
                          ->withHeaders([
                              'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                              'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                              'Accept-Language' => 'en-US,en;q=0.5',
                              'Accept-Encoding' => 'gzip, deflate', // Enable compression to save bandwidth
                              'Connection'      => 'close', // Don't keep connection alive
                              'Cache-Control'   => 'no-cache',
                          ])
                          ->get($url);

            if (!$response->successful()) {
                throw new \Exception("HTTP {$response->status()} error");
            }

            $html = $response->body();

            // Early exit if page is too large (likely not a standard product page)
            if (strlen($html) > 2 * 1024 * 1024) { // 2MB limit
                throw new \Exception('Response too large, likely not a product page');
            }

            $crawler = new Crawler($html);

            // Try multiple selectors to find total review count (ordered by likelihood)
            $reviewCountSelectors = [
                '[data-hook="total-review-count"]', // Most common and reliable
                'span[data-hook="total-review-count"]',
                '.a-size-base.a-color-base[data-hook="total-review-count"]',
                '[data-hook="cr-filter-info-review-rating-count"]',
                '.cr-pivot-review-count-info .totalReviewCount',
                // Fallback selectors (less reliable, tried last)
                '.a-row.a-spacing-medium .a-size-base',
                '.a-text-normal',
            ];

            foreach ($reviewCountSelectors as $selector) {
                try {
                    $countNode = $crawler->filter($selector);
                    if ($countNode->count() > 0) {
                        $countText = trim($countNode->text());

                        // Extract number from various text patterns
                        $patterns = [
                            '/([0-9,]+)\s*(?:global\s+)?(?:ratings?|reviews?)/i',
                            '/([0-9,]+)\s*(?:customer\s+)?(?:ratings?|reviews?)/i',
                            '/([0-9,]+)\s*(?:ratings?|reviews?)/i',
                            '/([0-9,]+)\s*(?:total|all)\s*(?:ratings?|reviews?)/i',
                        ];

                        foreach ($patterns as $pattern) {
                            if (preg_match($pattern, $countText, $matches)) {
                                $totalReviews = (int) str_replace(',', '', $matches[1]);

                                // Sanity check: must be a reasonable number
                                if ($totalReviews > 0 && $totalReviews < 1000000) {
                                    $this->line("✅ Found {$totalReviews} total reviews for {$asin}");

                                    return $totalReviews;
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                    continue;
                }
            }

            // If no selectors worked, try to find any number that looks like a review count
            // Only search in a subset of text to improve performance
            try {
                $reviewSection = $crawler->filter('#reviewsMedley, #reviews, .cr-pivot-review')->first();
                $searchText = $reviewSection->count() > 0 ? $reviewSection->text() : $crawler->text();

                if (preg_match('/(\d{1,3}(?:,\d{3})*)\s*(?:global\s+)?(?:ratings?|reviews?)/i', $searchText, $matches)) {
                    $totalReviews = (int) str_replace(',', '', $matches[1]);
                    if ($totalReviews > 0 && $totalReviews < 1000000) {
                        $this->line("✅ Found {$totalReviews} total reviews for {$asin} (text search)");

                        return $totalReviews;
                    }
                }
            } catch (\Exception $e) {
                // Fallback to full text search if review section not found
                $allText = $crawler->text();
                if (preg_match('/(\d{1,3}(?:,\d{3})*)\s*(?:global\s+)?(?:ratings?|reviews?)/i', $allText, $matches)) {
                    $totalReviews = (int) str_replace(',', '', $matches[1]);
                    if ($totalReviews > 0 && $totalReviews < 1000000) {
                        $this->line("✅ Found {$totalReviews} total reviews for {$asin} (fallback search)");

                        return $totalReviews;
                    }
                }
            }

            $this->line("⚠️  Could not extract total review count for {$asin}");

            return null;
        } catch (\Exception $e) {
            $this->line("❌ Error processing {$asin}: ".$e->getMessage());

            throw $e;
        }
    }
}
