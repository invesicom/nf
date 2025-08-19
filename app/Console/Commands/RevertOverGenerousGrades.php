<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use Illuminate\Console\Command;

class RevertOverGenerousGrades extends Command
{
    protected $signature = 'revert:over-generous-grades 
                            {--dry-run : Show what would be changed without making changes}
                            {--limit=100 : Limit number of products to process}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Revert products that were made too generous (all A grades) back to more realistic grades';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit');
        $force = $this->option('force');

        $this->info("Checking for products that may have been over-corrected to Grade A...");

        // Find products that:
        // 1. Are currently Grade A (fake_percentage <= 15)
        // 2. Have analysis notes mentioning "generous adjustment" 
        // 3. Were recently updated (likely from our batch operation)
        $suspiciousProducts = AsinData::where('grade', 'A')
            ->where('fake_percentage', '<=', 15)
            ->where('analysis_notes', 'like', '%generous adjustment%')
            ->where('updated_at', '>', now()->subHours(24)) // Updated in last 24 hours
            ->limit($limit)
            ->get();

        if ($suspiciousProducts->isEmpty()) {
            $this->info("No suspicious Grade A products found that need reverting.");
            return 0;
        }

        $this->info("Found {$suspiciousProducts->count()} products that may have been over-corrected:");
        $this->newLine();

        // Show preview
        foreach ($suspiciousProducts->take(10) as $product) {
            $this->line("ASIN: {$product->asin} | Grade: {$product->grade} | Fake: {$product->fake_percentage}%");
            
            // Try to extract original percentage from analysis notes
            if (preg_match('/adjustment \(-([0-9.]+)%\)/', $product->analysis_notes, $matches)) {
                $reduction = floatval($matches[1]);
                $estimatedOriginal = $product->fake_percentage + $reduction;
                $this->line("  Estimated original: {$estimatedOriginal}%");
            }
        }

        if ($suspiciousProducts->count() > 10) {
            $this->line("... and " . ($suspiciousProducts->count() - 10) . " more");
        }

        if ($dryRun) {
            $this->newLine();
            $this->info("DRY RUN MODE - No changes would be made");
            $this->info("Run without --dry-run to apply more conservative adjustments");
            return 0;
        }

        if (!$force && !$this->confirm("Apply more conservative adjustments to these products?")) {
            $this->info("Operation cancelled.");
            return 0;
        }

        $this->newLine();
        $this->info("Applying more conservative adjustments...");
        
        $progressBar = $this->output->createProgressBar($suspiciousProducts->count());
        $progressBar->start();

        $reverted = 0;
        foreach ($suspiciousProducts as $product) {
            // Apply more conservative logic
            $currentFake = $product->fake_percentage;
            
            // Try to estimate what a more reasonable fake percentage would be
            // Look for the original reduction in analysis notes
            $originalReduction = 0;
            if (preg_match('/adjustment \(-([0-9.]+)%\)/', $product->analysis_notes, $matches)) {
                $originalReduction = floatval($matches[1]);
            }
            
            // Calculate what the original fake percentage likely was
            $estimatedOriginal = $currentFake + $originalReduction;
            
            // Apply a more moderate 10% reduction instead of the aggressive reduction
            $newFakePercentage = $estimatedOriginal * 0.9; // 10% reduction
            
            // Ensure reasonable bounds (25% minimum for products that were originally high)
            if ($estimatedOriginal > 70) {
                $newFakePercentage = max(25, min(75, $newFakePercentage));
            } else {
                $newFakePercentage = max(20, min(65, $newFakePercentage));
            }
            
            $newGrade = $this->calculateGradeFromPercentage($newFakePercentage);
            
            // Only update if it actually changes the grade
            if ($newGrade !== $product->grade) {
                $product->update([
                    'fake_percentage' => round($newFakePercentage, 1),
                    'grade' => $newGrade,
                    'analysis_notes' => $product->analysis_notes . '; ' . 
                                       "Reverted over-generous adjustment to more conservative " . 
                                       round($newFakePercentage, 1) . "% on " . now(),
                ]);
                $reverted++;
            }
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Reversion complete:");
        $this->info("Processed: {$suspiciousProducts->count()} products");
        $this->info("Reverted: {$reverted} products to more conservative grades");

        return 0;
    }

    private function calculateGradeFromPercentage(float $fakePercentage): string
    {
        if ($fakePercentage <= 15) return 'A';
        if ($fakePercentage <= 30) return 'B';
        if ($fakePercentage <= 50) return 'C';
        if ($fakePercentage <= 70) return 'D';
        return 'F';
    }
}
