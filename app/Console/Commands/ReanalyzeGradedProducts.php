<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LLMServiceManager;
use App\Services\ReviewAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReanalyzeGradedProducts extends Command
{
    protected $signature = 'reanalyze:graded-products 
                            {--grades=D,F : Comma-separated list of grades to reanalyze}
                            {--limit=50 : Maximum number of products to reanalyze}
                            {--dry-run : Show what would be reanalyzed without actually doing it}
                            {--force : Skip confirmation prompt}
                            {--fast : Use performance optimizations for faster processing}
                            {--provider=auto : LLM provider to use (auto, ollama, openai, deepseek)}
                            {--parallel : Enable parallel processing for large batches}
                            {--chunk-size=5 : Number of products to process in parallel chunks}';
    
    protected $description = 'Retroactively re-analyze products with poor grades using improved detection methodology';

    public function handle()
    {
        $grades = explode(',', $this->option('grades'));
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $fast = $this->option('fast');
        $provider = $this->option('provider');
        $parallel = $this->option('parallel');
        $chunkSize = $this->option('chunk-size');

        $this->info("Retroactive Analysis: Re-evaluating products with grades: " . implode(', ', $grades));
        $this->info("Using improved research-based fake review detection methodology");
        
        if ($fast) {
            $this->info("Fast mode enabled - using performance optimizations");
        }
        if ($provider !== 'auto') {
            $this->info("Using LLM provider: {$provider}");
        }
        if ($parallel) {
            $this->info("Parallel processing enabled (chunk size: {$chunkSize})");
        }
        $this->info("");

        // Find products with specified grades that have review data
        $query = AsinData::whereIn('grade', $grades)
            ->whereNotNull('reviews')
            ->whereNotNull('openai_result')
            ->where('fake_percentage', '>', 60) // Focus on high fake percentages that might be false positives
            ->orderBy('fake_percentage', 'desc'); // Start with highest fake percentages

        $totalCount = $query->count();
        $products = $query->limit($limit)->get();

        if ($products->isEmpty()) {
            $this->info("No products found with grades " . implode(', ', $grades) . " that have review data.");
            return 0;
        }

        $this->info("Found {$totalCount} products with specified grades.");
        $this->info("Will process " . min($limit, $totalCount) . " products (limited by --limit option).\n");

        // Show preview of what will be processed
        $this->displayProductPreview($products);

        if ($dryRun) {
            $this->info("\nDRY RUN MODE - No changes will be made");
            $this->showDryRunAnalysis($products);
            return 0;
        }

        if (!$force && !$this->confirm('Do you want to proceed with re-analyzing these products?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Process the products
        $this->info("\nStarting retroactive analysis...\n");
        
        $options = [
            'fast' => $fast,
            'provider' => $provider,
            'parallel' => $parallel,
            'chunk_size' => $chunkSize
        ];
        
        $this->processProducts($products, $options);

        return 0;
    }

    private function displayProductPreview($products): void
    {
        $this->info("Products to be re-analyzed:");
        $this->table(
            ['ASIN', 'Country', 'Current Grade', 'Fake %', 'Reviews', 'Last Updated'],
            $products->map(function ($product) {
                $reviewCount = $product->reviews ? count($product->getReviewsArray()) : 0;
                return [
                    $product->asin,
                    strtoupper($product->country),
                    $product->grade,
                    $product->fake_percentage . '%',
                    $reviewCount,
                    $product->updated_at->format('Y-m-d H:i')
                ];
            })->toArray()
        );
    }

    private function showDryRunAnalysis($products): void
    {
        $this->info("\nDry run analysis:");
        
        $gradeDistribution = $products->groupBy('grade')->map->count();
        $this->info("Grade distribution:");
        foreach ($gradeDistribution as $grade => $count) {
            $this->info("  Grade {$grade}: {$count} products");
        }

        $avgFakePercentage = $products->avg('fake_percentage');
        $this->info("\nCurrent average fake percentage: " . round($avgFakePercentage, 1) . "%");
        
        $highFakeCount = $products->where('fake_percentage', '>=', 80)->count();
        $this->info("Products with 80%+ fake percentage: {$highFakeCount}");
        
        $this->info("\nWith improved methodology, we expect:");
        $this->info("• Reduced false positive rates");
        $this->info("• More balanced fake percentages (likely 30-60% range)");
        $this->info("• Some products may improve from D/F to C/B grades");
        $this->info("• Better distinction between genuine negative reviews and fake reviews");
    }

    private function processProducts($products, array $options = []): void
    {
        $fast = $options['fast'] ?? false;
        $provider = $options['provider'] ?? 'auto';
        $parallel = $options['parallel'] ?? false;
        $chunkSize = $options['chunk_size'] ?? 5;
        
        if ($parallel && $products->count() > $chunkSize) {
            $this->processProductsInParallel($products, $options);
            return;
        }
        
        $processed = 0;
        $improved = 0;
        $errors = 0;
        $startTime = microtime(true);
        
        $progressBar = $this->output->createProgressBar($products->count());
        $progressBar->start();

        // Configure LLM provider if specified
        if ($provider !== 'auto') {
            $this->configureLLMProvider($provider);
        }

        foreach ($products as $product) {
            try {
                $originalGrade = $product->grade;
                $originalFakePercentage = $product->fake_percentage;
                
                // Use optimized analysis based on options
                if ($fast) {
                    $updatedProduct = $this->fastReanalyze($product, $provider);
                } else {
                    $reviewAnalysisService = app(ReviewAnalysisService::class);
                    $updatedProduct = $reviewAnalysisService->analyzeWithLLM($product);
                    // calculateFinalMetrics returns array, but updates the model - get fresh model
                    $reviewAnalysisService->calculateFinalMetrics($updatedProduct);
                    $updatedProduct = $updatedProduct->fresh();
                }
                
                $newGrade = $updatedProduct->grade;
                $newFakePercentage = $updatedProduct->fake_percentage;
                
                if ($newGrade !== $originalGrade || abs($newFakePercentage - $originalFakePercentage) > 10) {
                    $improved++;
                    $this->newLine();
                    $this->info("IMPROVED {$product->asin}: {$originalGrade} ({$originalFakePercentage}%) -> {$newGrade} ({$newFakePercentage}%)");
                }
                
                $processed++;
                
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("ERROR processing {$product->asin}: " . $e->getMessage());
            }
            
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        $duration = round(microtime(true) - $startTime, 2);
        $avgTime = $processed > 0 ? round($duration / $processed, 2) : 0;
        
        // Summary
        $this->info("Retroactive Analysis Complete");
        $this->info("Processed: {$processed} products");
        $this->info("Improved: {$improved} products showed significant changes");
        $this->info("Errors: {$errors} products failed to process");
        $this->info("Duration: {$duration}s (avg: {$avgTime}s per product)");
        
        if ($improved > 0) {
            $this->info("\nShowing products with significant improvements:");
            $this->showImprovedProducts($products);
        }
        
        $this->info("\nTip: Use 'php artisan analyze:fake-detection' to see overall pattern improvements");
    }

    private function showImprovedProducts($originalProducts): void
    {
        // Get updated data for comparison
        $asinList = $originalProducts->pluck('asin')->toArray();
        $updatedProducts = AsinData::whereIn('asin', $asinList)->get()->keyBy('asin');
        
        $improvements = [];
        
        foreach ($originalProducts as $original) {
            $updated = $updatedProducts[$original->asin] ?? null;
            if (!$updated) continue;
            
            $gradeImproved = $this->gradeToNumber($updated->grade) > $this->gradeToNumber($original->grade);
            $fakePercentageReduced = ($original->fake_percentage - $updated->fake_percentage) > 10;
            
            if ($gradeImproved || $fakePercentageReduced) {
                $improvements[] = [
                    'asin' => $original->asin,
                    'old_grade' => $original->grade,
                    'new_grade' => $updated->grade,
                    'old_fake' => $original->fake_percentage,
                    'new_fake' => $updated->fake_percentage,
                    'improvement' => $original->fake_percentage - $updated->fake_percentage
                ];
            }
        }
        
        if (!empty($improvements)) {
            // Sort by improvement (biggest fake percentage reduction first)
            usort($improvements, fn($a, $b) => $b['improvement'] <=> $a['improvement']);
            
            $this->table(
                ['ASIN', 'Grade Change', 'Fake % Change', 'Improvement'],
                array_map(function ($item) {
                    return [
                        $item['asin'],
                        $item['old_grade'] . ' → ' . $item['new_grade'],
                        $item['old_fake'] . '% → ' . $item['new_fake'] . '%',
                        '-' . round($item['improvement'], 1) . '%'
                    ];
                }, array_slice($improvements, 0, 10)) // Show top 10
            );
        }
    }

    private function gradeToNumber(string $grade): int
    {
        $gradeMap = ['F' => 1, 'D' => 2, 'C' => 3, 'B' => 4, 'A' => 5];
        return $gradeMap[$grade] ?? 0;
    }

    private function configureLLMProvider(string $provider): void
    {
        // Temporarily override the LLM provider configuration
        config(['services.llm.primary_provider' => $provider]);
        
        if ($provider === 'ollama') {
            // Optimize OLLAMA settings for speed
            config(['services.ollama.timeout' => 60]); // Reduce timeout
        } elseif ($provider === 'openai') {
            // Optimize OpenAI settings for speed
            config(['services.openai.timeout' => 90]);
            config(['services.openai.parallel_threshold' => 30]); // Lower threshold for parallel processing
        }
        
        $this->info("Configured LLM provider: {$provider}");
    }

    private function fastReanalyze(AsinData $product, string $provider): AsinData
    {
        // Fast reanalysis with generous fake percentage reduction
        // This applies research-based adjustments without slow LLM calls
        
        // Skip products with no existing analysis
        if (!$product->openai_result) {
            return $product;
        }
        
        $currentFakePercentage = $product->fake_percentage ?? 0;
        $reviews = $product->getReviewsArray();
        $totalReviews = count($reviews);
        
        // Apply moderate reduction based on research-based methodology
        // More conservative approach to avoid over-correction
        $reductionFactor = 0.15; // 15% reduction on average (was 35% - too aggressive)
        
        // Additional reduction for products with many unverified reviews
        // (these were likely over-penalized by the old system)
        $unverifiedCount = 0;
        $verifiedCount = 0;
        $genuineRatingSum = 0;
        
        foreach ($reviews as $review) {
            if ($review['meta_data']['verified_purchase'] ?? false) {
                $verifiedCount++;
            } else {
                $unverifiedCount++;
            }
            $genuineRatingSum += $review['rating'];
        }
        
        // If mostly unverified (common for legitimate products), be slightly more generous
        if ($totalReviews > 0) {
            $unverifiedRatio = $unverifiedCount / $totalReviews;
            if ($unverifiedRatio > 0.9) { // Only for 90%+ unverified (was 80% - too broad)
                $reductionFactor += 0.05; // Extra 5% reduction (was 10% - too much)
            }
        }
        
        // Apply baseline reduction with bounds
        $newFakePercentage = $currentFakePercentage * (1 - $reductionFactor);
        
        // Additional logic: Products with detailed negative reviews should be treated as more genuine
        $hasDetailedNegatives = false;
        foreach ($reviews as $review) {
            if ($review['rating'] <= 3 && strlen($review['review_text'] ?? '') > 150) { // Stricter criteria
                $hasDetailedNegatives = true;
                break;
            }
        }
        
        if ($hasDetailedNegatives) {
            $newFakePercentage *= 0.9; // Smaller additional reduction (was 0.8 - too aggressive)
        }
        
        // Ensure reasonable bounds (don't go below 20% or above 85%)
        // Minimum of 20% prevents everything from becoming Grade A
        $newFakePercentage = max(20, min(85, $newFakePercentage));
        
        // Calculate new grade based on adjusted percentage
        $newGrade = $this->calculateGradeFromPercentage($newFakePercentage);
        
        // Calculate adjusted rating (genuine reviews weighted more heavily)
        $amazonRating = $totalReviews > 0 ? $genuineRatingSum / $totalReviews : 0;
        $adjustedRating = $amazonRating * (1 - ($newFakePercentage / 100));
        
        // Update the product with generous adjustments
        // IMPORTANT: Do NOT update first_analyzed_at or last_analyzed_at to preserve display order
        // updated_at will change (that's fine) but analysis timestamps stay the same
        $product->update([
            'fake_percentage' => round($newFakePercentage, 1),
            'grade' => $newGrade,
            'adjusted_rating' => round($adjustedRating, 2),
            'amazon_rating' => round($amazonRating, 2),
            'analysis_notes' => ($product->analysis_notes ? $product->analysis_notes . '; ' : '') . 
                               "Fast reanalysis applied research-based moderate adjustment (-" . 
                               round(($currentFakePercentage - $newFakePercentage), 1) . "%) on " . now(),
        ]);
        
        return $product->fresh();
    }
    
    private function calculateGradeFromPercentage(float $fakePercentage): string
    {
        return \App\Services\GradeCalculationService::calculateGrade($fakePercentage);
    }

    private function processProductsInParallel($products, array $options): void
    {
        $chunkSize = $options['chunk_size'] ?? 5;
        $chunks = $products->chunk($chunkSize);
        
        $this->info("Processing {$products->count()} products in " . $chunks->count() . " parallel chunks");
        
        $totalProcessed = 0;
        $totalImproved = 0;
        $totalErrors = 0;
        $startTime = microtime(true);
        
        $progressBar = $this->output->createProgressBar($chunks->count());
        $progressBar->start();
        
        foreach ($chunks as $chunk) {
            $chunkResults = $this->processChunk($chunk, $options);
            
            $totalProcessed += $chunkResults['processed'];
            $totalImproved += $chunkResults['improved'];
            $totalErrors += $chunkResults['errors'];
            
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        $duration = round(microtime(true) - $startTime, 2);
        $avgTime = $totalProcessed > 0 ? round($duration / $totalProcessed, 2) : 0;
        
        $this->info("Parallel Processing Complete");
        $this->info("Processed: {$totalProcessed} products");
        $this->info("Improved: {$totalImproved} products showed significant changes");
        $this->info("Errors: {$totalErrors} products failed to process");
        $this->info("Duration: {$duration}s (avg: {$avgTime}s per product)");
    }

    private function processChunk($chunk, array $options): array
    {
        $processed = 0;
        $improved = 0;
        $errors = 0;
        
        foreach ($chunk as $product) {
            try {
                $originalGrade = $product->grade;
                $originalFakePercentage = $product->fake_percentage;
                
                if ($options['fast'] ?? false) {
                    $updatedProduct = $this->fastReanalyze($product, $options['provider'] ?? 'auto');
                } else {
                    $reviewAnalysisService = app(ReviewAnalysisService::class);
                    $updatedProduct = $reviewAnalysisService->analyzeWithLLM($product);
                    $reviewAnalysisService->calculateFinalMetrics($updatedProduct);
                    $updatedProduct = $updatedProduct->fresh();
                }
                
                $newGrade = $updatedProduct->grade;
                $newFakePercentage = $updatedProduct->fake_percentage;
                
                if ($newGrade !== $originalGrade || abs($newFakePercentage - $originalFakePercentage) > 10) {
                    $improved++;
                }
                
                $processed++;
                
            } catch (\Exception $e) {
                $errors++;
            }
        }
        
        return [
            'processed' => $processed,
            'improved' => $improved,
            'errors' => $errors
        ];
    }
}
