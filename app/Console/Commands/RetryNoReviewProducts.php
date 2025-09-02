<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LoggingService;
use App\Services\ReviewAnalysisService;
use Illuminate\Console\Command;

class RetryNoReviewProducts extends Command
{
    protected $signature = 'products:retry-no-reviews 
                           {--limit=50 : Maximum number of products to retry}
                           {--age=24 : Minimum age in hours before retrying}
                           {--dry-run : Show what would be processed without making changes}
                           {--force : Skip confirmation prompt}';

    protected $description = 'Retry review extraction for products that had no reviews found';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $ageHours = (int) $this->option('age');
        $dryRun = $this->option('dry-run');

        $this->info("Searching for products with no reviews to retry...");
        $this->info("Criteria: Grade U, analyzed >{$ageHours}h ago, limit: {$limit}");

        // Find products that:
        // 1. Have Grade U (no reviews)
        // 2. Were analyzed more than X hours ago
        // 3. Have product data (so they exist on Amazon)
        // 4. Are in completed status (not failed)
        $products = AsinData::where('grade', 'U')
            ->where('status', 'completed')
            ->where('have_product_data', true)
            ->where('last_analyzed_at', '<', now()->subHours($ageHours))
            ->whereNotNull('last_analyzed_at')
            ->orderBy('last_analyzed_at', 'asc') // Retry oldest first
            ->limit($limit)
            ->get();

        if ($products->isEmpty()) {
            $this->info("No products found matching retry criteria.");
            return 0;
        }

        $this->info("Found {$products->count()} products to retry:");
        
        $table = [];
        foreach ($products as $product) {
            $table[] = [
                'ASIN' => $product->asin,
                'Country' => $product->country,
                'Title' => substr($product->product_title ?? 'No title', 0, 50),
                'Analyzed' => $product->last_analyzed_at?->diffForHumans(),
                'Reviews' => count($product->getReviewsArray()),
            ];
        }
        
        $this->table(['ASIN', 'Country', 'Title', 'Analyzed', 'Reviews'], $table);

        if ($dryRun) {
            $this->warn("DRY RUN: No changes made. Use without --dry-run to process.");
            return 0;
        }

        if (!$this->option('force') && !$this->confirm("Proceed with retrying {$products->count()} products?")) {
            $this->info("Operation cancelled.");
            return 0;
        }

        $analysisService = app(ReviewAnalysisService::class);
        $successCount = 0;
        $errorCount = 0;

        foreach ($products as $product) {
            try {
                $this->info("Retrying ASIN: {$product->asin} ({$product->country})");
                
                // Reset the product to allow re-analysis
                $product->update([
                    'status' => 'pending_analysis',
                    'openai_result' => null,
                    'fake_percentage' => null,
                    'grade' => null,
                    'explanation' => null,
                    'last_analyzed_at' => null,
                ]);

                // Build the product URL for re-analysis
                $productUrl = "https://www.amazon.{$this->getDomainForCountry($product->country)}/dp/{$product->asin}";
                
                // Perform fresh analysis
                $result = $analysisService->analyzeProduct($product->asin, $product->country);
                
                if ($result['success']) {
                    $reviewCount = count($result['asin_data']->getReviewsArray());
                    $newGrade = $result['asin_data']->grade;
                    
                    if ($reviewCount > 0) {
                        $this->info("SUCCESS: Found {$reviewCount} reviews, Grade: {$newGrade}");
                        $successCount++;
                    } else {
                        $this->warn("Still no reviews found for {$product->asin}");
                    }
                } else {
                    $this->error("FAILED: {$result['error']}");
                    $errorCount++;
                }

                // Small delay to avoid overwhelming services
                sleep(2);

            } catch (\Exception $e) {
                $this->error("ERROR processing {$product->asin}: {$e->getMessage()}");
                LoggingService::log("Retry failed for ASIN: {$product->asin}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errorCount++;
            }
        }

        $this->info("\nRetry Summary:");
        $this->info("Processed: {$products->count()}");
        $this->info("Success: {$successCount}");
        $this->info("Errors: {$errorCount}");

        LoggingService::log("Completed retry of no-review products", [
            'processed' => $products->count(),
            'success' => $successCount,
            'errors' => $errorCount,
        ]);

        return 0;
    }

    private function getDomainForCountry(string $country): string
    {
        $domains = [
            'us' => 'com',
            'gb' => 'co.uk',
            'ca' => 'ca',
            'de' => 'de',
            'fr' => 'fr',
            'it' => 'it',
            'es' => 'es',
            'jp' => 'co.jp',
            'au' => 'com.au',
            'mx' => 'com.mx',
            'in' => 'in',
            'sg' => 'sg',
            'br' => 'com.br',
            'nl' => 'nl',
            'tr' => 'com.tr',
            'ae' => 'ae',
            'sa' => 'sa',
            'se' => 'se',
            'pl' => 'pl',
            'eg' => 'eg',
            'be' => 'be',
        ];

        return $domains[$country] ?? 'com';
    }
}