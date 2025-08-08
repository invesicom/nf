<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AsinData;
use App\Services\LoggingService;
use App\Services\ReviewAnalysisService;

class AuditReviewDuplication extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'audit:review-duplication 
                            {--limit=100 : Maximum number of products to audit}
                            {--fix : Automatically fix duplicates found}
                            {--asin= : Audit specific ASIN}
                            {--threshold=10 : Minimum duplicate percentage to report}
                            {--older-than= : Only audit products older than X days (e.g., 7)}
                            {--reanalyze : Re-run LLM analysis after fixing duplicates}';

    /**
     * The console command description.
     */
    protected $description = 'Audit products for review duplication issues and optionally fix them';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Auditing Amazon review data for duplication issues...');
        $this->info('GitHub Issue: https://github.com/stardothosting/nullfake/issues/54');
        $this->newLine();

        $limit = (int) $this->option('limit');
        $fix = $this->option('fix');
        $specificAsin = $this->option('asin');
        $threshold = (int) $this->option('threshold');
        $olderThanDays = $this->option('older-than');
        $reanalyze = $this->option('reanalyze');

        if ($specificAsin) {
            return $this->auditSpecificProduct($specificAsin, $fix, $reanalyze);
        }

        return $this->auditAllProducts($limit, $threshold, $fix, $olderThanDays, $reanalyze);
    }

    /**
     * Audit a specific product by ASIN
     */
    private function auditSpecificProduct(string $asin, bool $fix, bool $reanalyze = false): int
    {
        $product = AsinData::where('asin', $asin)->first();
        
        if (!$product) {
            $this->error("❌ ASIN {$asin} not found in database");
            return 1;
        }

        $this->info("📦 Auditing product: {$asin}");
        if ($product->product_title) {
            $this->line("   Title: " . substr($product->product_title, 0, 80) . '...');
        }

        $analysis = $this->analyzeReviewDuplication($product);
        $this->displayDuplicationAnalysis($product, $analysis);

        if ($fix && $analysis['has_duplicates']) {
            $result = $this->fixProductDuplication($product, $analysis);
            
            if ($reanalyze && $result === 0) {
                $this->reanalyzeProduct($product);
            }
            
            return $result;
        }

        return 0;
    }

    /**
     * Audit all products in the database
     */
    private function auditAllProducts(int $limit, int $threshold, bool $fix, ?string $olderThanDays = null, bool $reanalyze = false): int
    {
        $query = AsinData::whereNotNull('reviews')
                         ->where('reviews', '!=', '[]');
        
        if ($olderThanDays) {
            $cutoffDate = now()->subDays((int) $olderThanDays);
            $query->where('updated_at', '<', $cutoffDate);
            $this->info("🕒 Filtering to products older than {$olderThanDays} days (before {$cutoffDate->format('Y-m-d H:i:s')})");
        }
        
        $query->orderBy('updated_at', 'desc');

        $totalProducts = $query->count();
        $this->info("📊 Found {$totalProducts} products with review data");
        
        if ($limit > 0) {
            $query->limit($limit);
            $this->info("🔬 Auditing first {$limit} products...");
        }

        $products = $query->get();
        $affectedProducts = [];
        $totalDuplicates = 0;
        $fixedProducts = 0;

        $progressBar = $this->output->createProgressBar($products->count());
        $progressBar->start();

        foreach ($products as $product) {
            $analysis = $this->analyzeReviewDuplication($product);
            
            if ($analysis['has_duplicates'] && $analysis['duplicate_percentage'] >= $threshold) {
                $affectedProducts[] = [
                    'asin' => $product->asin,
                    'title' => substr($product->product_title ?? 'No title', 0, 50),
                    'total_reviews' => $analysis['total_reviews'],
                    'unique_reviews' => $analysis['unique_reviews'],
                    'duplicates' => $analysis['duplicates'],
                    'duplicate_percentage' => $analysis['duplicate_percentage'],
                    'analysis' => $analysis
                ];
                
                $totalDuplicates += $analysis['duplicates'];

                if ($fix) {
                    if ($this->fixProductDuplication($product, $analysis) === 0) {
                        $fixedProducts++;
                        
                        if ($reanalyze) {
                            $this->reanalyzeProduct($product);
                        }
                    }
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->displayAuditSummary($affectedProducts, $totalDuplicates, $fixedProducts, $threshold);

        return 0;
    }

    /**
     * Analyze review duplication for a single product
     */
    private function analyzeReviewDuplication(AsinData $product): array
    {
        $reviews = $product->getReviewsArray();
        $totalReviews = count($reviews);
        
        if ($totalReviews === 0) {
            return [
                'has_duplicates' => false,
                'total_reviews' => 0,
                'unique_reviews' => 0,
                'duplicates' => 0,
                'duplicate_percentage' => 0,
                'most_duplicated' => []
            ];
        }

        // Extract review texts
        $reviewTexts = array_column($reviews, 'review_text');
        $reviewTexts = array_filter($reviewTexts); // Remove empty texts
        
        // Count occurrences of each review text
        $textCounts = array_count_values($reviewTexts);
        $uniqueReviews = count($textCounts);
        $duplicates = $totalReviews - $uniqueReviews;
        $duplicatePercentage = $totalReviews > 0 ? round(($duplicates / $totalReviews) * 100, 1) : 0;

        // Find most duplicated reviews
        arsort($textCounts);
        $mostDuplicated = array_slice($textCounts, 0, 5, true);
        
        // Only include reviews that appear more than once
        $mostDuplicated = array_filter($mostDuplicated, fn($count) => $count > 1);

        return [
            'has_duplicates' => $duplicates > 0,
            'total_reviews' => $totalReviews,
            'unique_reviews' => $uniqueReviews,
            'duplicates' => $duplicates,
            'duplicate_percentage' => $duplicatePercentage,
            'most_duplicated' => $mostDuplicated
        ];
    }

    /**
     * Display duplication analysis for a single product
     */
    private function displayDuplicationAnalysis(AsinData $product, array $analysis): void
    {
        if (!$analysis['has_duplicates']) {
            $this->info("✅ No duplicates found ({$analysis['total_reviews']} unique reviews)");
            return;
        }

        $this->error("❌ Duplication detected!");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Reviews', $analysis['total_reviews']],
                ['Unique Reviews', $analysis['unique_reviews']],
                ['Duplicates', $analysis['duplicates']],
                ['Duplicate %', $analysis['duplicate_percentage'] . '%'],
                ['Amazon Shows', $product->total_reviews_on_amazon ?? 'Unknown']
            ]
        );

        if (!empty($analysis['most_duplicated'])) {
            $this->warn("🔍 Most duplicated reviews:");
            foreach ($analysis['most_duplicated'] as $text => $count) {
                $truncated = substr($text, 0, 60) . '...';
                $this->line("   {$count}x: {$truncated}");
            }
        }

        $this->newLine();
    }

    /**
     * Display overall audit summary
     */
    private function displayAuditSummary(array $affectedProducts, int $totalDuplicates, int $fixedProducts, int $threshold): void
    {
        $affectedCount = count($affectedProducts);
        
        if ($affectedCount === 0) {
            $this->info("✅ No products found with duplicates above {$threshold}% threshold");
            return;
        }

        $this->error("❌ Found {$affectedCount} products with significant duplication:");
        $this->newLine();

        // Create summary table
        $tableData = [];
        foreach (array_slice($affectedProducts, 0, 20) as $product) { // Show top 20
            $tableData[] = [
                $product['asin'],
                substr($product['title'], 0, 30) . '...',
                $product['total_reviews'],
                $product['unique_reviews'],
                $product['duplicates'],
                $product['duplicate_percentage'] . '%'
            ];
        }

        $this->table(
            ['ASIN', 'Title', 'Total', 'Unique', 'Dupes', '%'],
            $tableData
        );

        if ($affectedCount > 20) {
            $this->warn("... and " . ($affectedCount - 20) . " more products");
        }

        // Summary stats
        $this->newLine();
        $this->info("📈 Summary Statistics:");
        $this->line("   • Products affected: {$affectedCount}");
        $this->line("   • Total duplicate reviews: {$totalDuplicates}");
        if ($fixedProducts > 0) {
            $this->line("   • Products fixed: {$fixedProducts}");
        }

        // Recommendations
        $this->newLine();
        $this->warn("💡 Recommendations:");
        $this->line("   1. Run with --fix to automatically remove duplicates");
        $this->line("   2. Re-analyze products after fixing to get accurate metrics");
        $this->line("   3. Monitor future scraping with improved deduplication");
    }

    /**
     * Fix duplication for a single product
     */
    private function fixProductDuplication(AsinData $product, array $analysis): int
    {
        if (!$analysis['has_duplicates']) {
            return 0;
        }

        $this->warn("🔧 Fixing duplication for {$product->asin}...");

        $reviews = $product->getReviewsArray();
        $uniqueReviews = [];
        $seenTexts = [];

        foreach ($reviews as $review) {
            $text = $review['review_text'] ?? '';
            
            if (empty($text)) {
                $uniqueReviews[] = $review; // Keep reviews without text as-is
                continue;
            }

            // Normalize text for comparison (same logic as scraping service)
            $normalizedText = $this->normalizeReviewText($text);
            
            if (!isset($seenTexts[$normalizedText])) {
                $uniqueReviews[] = $review;
                $seenTexts[$normalizedText] = true;
            }
        }

        // Update the product with deduplicated reviews
        $product->reviews = json_encode($uniqueReviews);
        $product->save();

        $removedCount = count($reviews) - count($uniqueReviews);
        $this->info("   ✅ Removed {$removedCount} duplicate reviews");
        $this->info("   📊 {$product->asin} now has " . count($uniqueReviews) . " unique reviews");

        // Log the fix for audit trail
        LoggingService::log("Review duplication fixed", [
            'asin' => $product->asin,
            'original_count' => count($reviews),
            'final_count' => count($uniqueReviews),
            'duplicates_removed' => $removedCount,
            'github_issue' => 'https://github.com/stardothosting/nullfake/issues/54'
        ]);

        return 0;
    }

    /**
     * Normalize review text for comparison (matches AmazonScrapingService logic)
     */
    private function normalizeReviewText(string $text): string
    {
        $normalized = trim($text);
        $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize whitespace
        $normalized = strtolower($normalized); // Case insensitive
        $normalized = preg_replace('/[^\w\s]/', '', $normalized); // Remove punctuation for fuzzy matching
        
        return $normalized;
    }

    /**
     * Re-run LLM analysis after deduplication
     */
    private function reanalyzeProduct(AsinData $product): void
    {
        $this->info("🤖 Re-running LLM analysis for {$product->asin}...");
        
        try {
            $reviewAnalysisService = app(ReviewAnalysisService::class);
            $reviews = $product->getReviewsArray();
            
            if (empty($reviews)) {
                $this->warn("   ⚠️ No reviews to analyze");
                return;
            }
            
            // Clear existing analysis data
            $product->openai_result = null;
            $product->fake_percentage = null;
            $product->grade = null;
            $product->adjusted_rating = null;
            $product->detailed_analysis = null;
            $product->fake_review_examples = null;
            
            // Re-run analysis
            $result = $reviewAnalysisService->analyzeWithOpenAI($product->asin, $reviews);
            
            if ($result) {
                // Update the product with new analysis
                $product->openai_result = json_encode($result);
                $product->fake_percentage = $result['fake_percentage'] ?? null;
                $product->grade = $result['grade'] ?? null;
                $product->adjusted_rating = $result['adjusted_rating'] ?? null;
                $product->detailed_analysis = $result['detailed_analysis'] ?? null;
                $product->fake_review_examples = $result['fake_review_examples'] ?? null;
                $product->save();
                
                $this->info("   ✅ Analysis updated: {$product->fake_percentage}% fake, Grade: {$product->grade}");
            } else {
                $this->warn("   ❌ Analysis failed");
            }
            
        } catch (\Exception $e) {
            $this->error("   ❌ Analysis error: " . $e->getMessage());
            LoggingService::log("Re-analysis failed", [
                'asin' => $product->asin,
                'error' => $e->getMessage()
            ]);
        }
    }
}
