<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\Amazon\AmazonScrapingService;
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

        $this->info("📊 Found {$products->count()} products to process" . ($force ? ' (including those with existing data)' : ''));
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

        if (!$this->confirm('Do you want to proceed with processing these products?')) {
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
                LoggingService::log("Failed to process ASIN {$product->asin}: " . $e->getMessage());
                $this->warn("\n⚠️  Failed to process {$product->asin}: " . $e->getMessage());
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
            $this->info("You can now view these products with enhanced review statistics.");
        }

        if ($failed > 0) {
            $this->warn("⚠️  {$failed} products failed to process. Check logs for details.");
        }

        return self::SUCCESS;
    }

    /**
     * Extract total review count from Amazon product page
     */
    private function extractTotalReviewCount(string $asin): ?int
    {
        try {
            $url = "https://www.amazon.com/dp/{$asin}";
            
            $response = Http::timeout(30)
                          ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                          ->get($url);

            if (!$response->successful()) {
                throw new \Exception("HTTP {$response->status()} error");
            }

            $html = $response->body();
            $crawler = new Crawler($html);

            // Try multiple selectors to find total review count
            $reviewCountSelectors = [
                '[data-hook="total-review-count"]',
                '.cr-pivot-review-count-info .totalReviewCount',
                '.a-size-base.a-color-base[data-hook="total-review-count"]',
                'span[data-hook="total-review-count"]',
                '.a-text-normal',
                '.a-text-normal span',
                // Additional selectors for different Amazon layouts
                '[data-hook="cr-filter-info-review-rating-count"]',
                '.a-row.a-spacing-medium .a-size-base',
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
                                    $this->line("✅ Found {$totalReviews} total reviews for {$asin} using selector: {$selector}");
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
            $allText = $crawler->text();
            if (preg_match('/(\d{1,3}(?:,\d{3})*)\s*(?:global\s+)?(?:ratings?|reviews?)/i', $allText, $matches)) {
                $totalReviews = (int) str_replace(',', '', $matches[1]);
                if ($totalReviews > 0 && $totalReviews < 1000000) {
                    $this->line("✅ Found {$totalReviews} total reviews for {$asin} using text search");
                    return $totalReviews;
                }
            }

            $this->line("⚠️  Could not extract total review count for {$asin}");
            return null;

        } catch (\Exception $e) {
            $this->line("❌ Error processing {$asin}: " . $e->getMessage());
            throw $e;
        }
    }
}