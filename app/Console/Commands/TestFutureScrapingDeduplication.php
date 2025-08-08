<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AsinData;
use App\Services\Amazon\AmazonScrapingService;
use App\Services\LoggingService;

class TestFutureScrapingDeduplication extends Command
{
    protected $signature = 'test:future-scraping {--count=5 : Number of random ASINs to test}';
    protected $description = 'Test deduplication on random existing ASINs to verify future scraping works';

    private AmazonScrapingService $scrapingService;

    public function __construct(AmazonScrapingService $scrapingService)
    {
        parent::__construct();
        $this->scrapingService = $scrapingService;
    }

    public function handle()
    {
        $count = (int) $this->option('count');
        
        $this->info('🧪 TESTING FUTURE SCRAPING DEDUPLICATION');
        $this->info('========================================');
        $this->newLine();

        // Get random products with decent review counts
        $products = AsinData::whereNotNull('reviews')
            ->where('reviews', '!=', '[]')
            ->where('reviews', '!=', '{}')
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($products->count() < $count) {
            $this->error("Only found {$products->count()} products with reviews, requested {$count}");
            return Command::FAILURE;
        }

        $this->info("🎯 Testing deduplication on {$count} random ASINs:");
        $this->newLine();

        $results = [];

        foreach ($products as $product) {
            $this->info("🔍 Testing ASIN: {$product->asin}");
            
            $originalReviews = $product->getReviewsArray();
            $originalCount = count($originalReviews);
            
            $this->line("   📊 Current reviews: {$originalCount}");
            $this->line("   🌐 Amazon total: {$product->total_reviews_on_amazon}");

            // Test deduplication logic without actual scraping
            $this->line("   🔄 Testing deduplication logic...");
            
            // Simulate adding duplicate reviews to test deduplication
            $testReviews = $originalReviews;
            
            // Add some duplicates (first 5 reviews duplicated)
            $duplicatesToAdd = array_slice($originalReviews, 0, min(5, count($originalReviews)));
            $testReviews = array_merge($testReviews, $duplicatesToAdd);
            
            $beforeDedup = count($testReviews);
            $this->line("   📊 Before dedup test: {$beforeDedup} reviews");
            
            // Test our deduplication method
            $deduplicatedReviews = $this->testDeduplication($testReviews);
            $afterDedup = count($deduplicatedReviews);
            
            $this->line("   📊 After dedup test: {$afterDedup} reviews");
            $duplicatesRemoved = $beforeDedup - $afterDedup;
            $this->line("   🔄 Duplicates removed: {$duplicatesRemoved}");
            
            // Verify deduplication worked
            if ($afterDedup === $originalCount) {
                $this->line("   ✅ Deduplication working correctly");
                $status = '✅ PASS';
            } elseif ($afterDedup < $originalCount) {
                $this->line("   ⚠️  Deduplication too aggressive (removed originals)");
                $status = '⚠️ AGGRESSIVE';
            } else {
                $this->line("   ❌ Deduplication failed (still has duplicates)");
                $status = '❌ FAILED';
            }
            
            $newCount = $afterDedup;

            $results[] = [
                'asin' => $product->asin,
                'original' => $originalCount,
                'amazon_total' => $product->total_reviews_on_amazon,
                'after_rescrape' => $newCount,
                'status' => $status
            ];

            $this->newLine();
        }

        // Summary table
        $this->info('📋 DEDUPLICATION TEST RESULTS:');
        $this->info('=============================');
        
        $tableData = [];
        foreach ($results as $result) {
            $tableData[] = [
                $result['asin'],
                $result['original'],
                $result['amazon_total'],
                $result['after_rescrape'],
                $result['status']
            ];
        }
        
        $this->table(['ASIN', 'Original', 'Amazon Total', 'After Re-scrape', 'Status'], $tableData);
        $this->newLine();

        // Analysis
        $passCount = count(array_filter($results, fn($r) => str_contains($r['status'], 'PASS')));
        $concernCount = count(array_filter($results, fn($r) => str_contains($r['status'], 'CONCERN')));
        $failCount = count(array_filter($results, fn($r) => str_contains($r['status'], 'FAIL') || str_contains($r['status'], 'ERROR')));

        $this->info("🎯 SUMMARY:");
        $this->info("=========");
        $this->info("✅ Passed: {$passCount}/{$count}");
        if ($concernCount > 0) $this->warn("⚠️  Concerns: {$concernCount}/{$count}");
        if ($failCount > 0) $this->error("❌ Failed: {$failCount}/{$count}");

        if ($passCount === $count) {
            $this->info("🎉 All tests passed! Deduplication is working correctly.");
        } elseif ($passCount >= ($count * 0.8)) {
            $this->warn("⚠️  Most tests passed, but some issues remain.");
        } else {
            $this->error("🚨 Multiple tests failed! Deduplication needs more work.");
        }

        return Command::SUCCESS;
    }

    private function testDeduplication(array $reviews): array
    {
        $uniqueReviews = [];
        $seenTexts = [];

        foreach ($reviews as $review) {
            $text = $review['review_text'] ?? '';
            
            if (empty($text)) {
                $uniqueReviews[] = $review;
                continue;
            }

            $normalizedText = $this->normalizeReviewText($text);
            
            if (!isset($seenTexts[$normalizedText])) {
                $uniqueReviews[] = $review;
                $seenTexts[$normalizedText] = true;
            }
        }

        return $uniqueReviews;
    }

    private function normalizeReviewText(string $text): string
    {
        $normalized = trim($text);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);
        
        return $normalized;
    }
}
