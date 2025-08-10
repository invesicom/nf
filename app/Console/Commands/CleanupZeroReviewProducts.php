<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LoggingService;
use Illuminate\Console\Command;

class CleanupZeroReviewProducts extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'products:cleanup-zero-reviews 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Delete without confirmation}
                            {--asin= : Clean up specific ASIN only}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up products with 0 reviews from the database so they can be re-analyzed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $specificAsin = $this->option('asin');

        $this->info('🧹 Cleaning up products with 0 reviews');
        $this->info('=====================================');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No actual deletion will occur');
            $this->newLine();
        }

        // Find all products to check for 0 reviews
        $query = AsinData::query();
        
        if ($specificAsin) {
            $query->where('asin', $specificAsin);
            $this->info("🎯 Processing specific ASIN: {$specificAsin}");
        }

        $allProducts = $query->get();
        
        // Filter to products with 0 reviews
        $zeroReviewProducts = $allProducts->filter(function ($product) {
            return count($product->getReviewsArray()) === 0;
        });

        if ($zeroReviewProducts->isEmpty()) {
            $this->info('✅ No products found with 0 reviews');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$zeroReviewProducts->count()} products with 0 reviews");
        $this->newLine();

        // Show details
        $tableData = [];
        foreach ($zeroReviewProducts->take(10) as $product) {
            $tableData[] = [
                $product->asin,
                $product->status ?? 'N/A',
                $product->grade ?? 'N/A',
                $product->created_at->format('M j, Y'),
                $product->product_title ? substr($product->product_title, 0, 40) . '...' : 'No title'
            ];
        }

        $this->table(['ASIN', 'Status', 'Grade', 'Created', 'Title'], $tableData);
        
        if ($zeroReviewProducts->count() > 10) {
            $remaining = $zeroReviewProducts->count() - 10;
            $this->info("... and {$remaining} more");
        }
        $this->newLine();

        if ($dryRun) {
            $this->info('💡 Run without --dry-run to perform actual cleanup');
            return Command::SUCCESS;
        }

        // Confirm deletion unless force mode or specific ASIN
        if (!$force && !$specificAsin) {
            if (!$this->confirm("🗑️  Delete {$zeroReviewProducts->count()} products with 0 reviews?")) {
                $this->info('❌ Cleanup cancelled');
                return Command::SUCCESS;
            }
        }

        // Perform deletion
        $this->info('🗑️  Deleting products with 0 reviews...');
        $progressBar = $this->output->createProgressBar($zeroReviewProducts->count());
        $progressBar->start();

        $deleted = 0;
        $errors = 0;

        foreach ($zeroReviewProducts as $product) {
            try {
                LoggingService::log('Deleting zero-review product', [
                    'asin' => $product->asin,
                    'status' => $product->status,
                    'created_at' => $product->created_at,
                    'reason' => 'cleanup_zero_reviews'
                ]);

                $product->delete();
                $deleted++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Failed to delete {$product->asin}: " . $e->getMessage());
                $errors++;
            }
            
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info("✅ Cleanup completed!");
        $this->info("   🗑️  Deleted: {$deleted} products");
        
        if ($errors > 0) {
            $this->warn("   ⚠️  Errors: {$errors} products");
        }

        $this->newLine();
        $this->info("💡 These products can now be re-analyzed if users request them again");

        return Command::SUCCESS;
    }
}
