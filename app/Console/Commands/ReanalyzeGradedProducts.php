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
                            {--force : Skip confirmation prompt}';
    
    protected $description = 'Retroactively re-analyze products with poor grades using improved detection methodology';

    public function handle()
    {
        $grades = explode(',', $this->option('grades'));
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info("Retroactive Analysis: Re-evaluating products with grades: " . implode(', ', $grades));
        $this->info("Using improved research-based fake review detection methodology\n");

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
            $this->info("\n🔍 DRY RUN MODE - No changes will be made");
            $this->showDryRunAnalysis($products);
            return 0;
        }

        if (!$force && !$this->confirm('Do you want to proceed with re-analyzing these products?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Process the products
        $this->info("\n🔄 Starting retroactive analysis...\n");
        $this->processProducts($products);

        return 0;
    }

    private function displayProductPreview($products): void
    {
        $this->info("Products to be re-analyzed:");
        $this->table(
            ['ASIN', 'Country', 'Current Grade', 'Fake %', 'Reviews', 'Last Updated'],
            $products->map(function ($product) {
                $reviewCount = $product->reviews ? count(json_decode($product->reviews, true)) : 0;
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

    private function processProducts($products): void
    {
        $processed = 0;
        $improved = 0;
        $errors = 0;
        
        $progressBar = $this->output->createProgressBar($products->count());
        $progressBar->start();

        foreach ($products as $product) {
            try {
                $originalGrade = $product->grade;
                $originalFakePercentage = $product->fake_percentage;
                
                // Re-analyze using the improved methodology
                $reviewAnalysisService = app(ReviewAnalysisService::class);
                $updatedProduct = $reviewAnalysisService->analyzeWithOpenAI($product);
                
                // Recalculate final metrics (this will use the new analysis results)
                $updatedProduct = $reviewAnalysisService->calculateFinalMetrics($updatedProduct);
                
                $newGrade = $updatedProduct->grade;
                $newFakePercentage = $updatedProduct->fake_percentage;
                
                if ($newGrade !== $originalGrade || abs($newFakePercentage - $originalFakePercentage) > 10) {
                    $improved++;
                    $this->newLine();
                    $this->info("✅ {$product->asin}: {$originalGrade} ({$originalFakePercentage}%) → {$newGrade} ({$newFakePercentage}%)");
                }
                
                $processed++;
                
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("❌ Error processing {$product->asin}: " . $e->getMessage());
            }
            
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);
        
        // Summary
        $this->info("🎯 Retroactive Analysis Complete!");
        $this->info("Processed: {$processed} products");
        $this->info("Improved: {$improved} products showed significant changes");
        $this->info("Errors: {$errors} products failed to process");
        
        if ($improved > 0) {
            $this->info("\n📊 Showing products with significant improvements:");
            $this->showImprovedProducts($products);
        }
        
        $this->info("\n💡 Tip: Use 'php artisan analyze:fake-detection' to see overall pattern improvements");
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
}
