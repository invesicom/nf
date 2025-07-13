<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeAmazonProductData;
use App\Models\AsinData;
use App\Services\LoggingService;
use App\Services\Amazon\AmazonProductDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class ProcessExistingAsinData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'asin:process-existing 
                            {--batch-size=10 : Number of records to process in each batch}
                            {--delay=5 : Delay in seconds between batches to avoid rate limiting}
                            {--force : Process all records, even those already processed}
                            {--dry-run : Show what would be processed without actually processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retroactively process existing ASIN records to scrape product data directly';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info('🚀 Starting ASIN Data Processing');
        $this->info('=====================================');

        // Build query for records that need processing
        $query = AsinData::query();
        
        if (!$force) {
            // Only process records that don't have product data yet
            $query->where(function ($q) {
                $q->where('have_product_data', false)
                  ->orWhereNull('have_product_data');
            });
        }

        // Only process records that have been analyzed (have reviews and OpenAI results)
        $query->whereNotNull('reviews')
              ->whereNotNull('openai_result')
              ->where('reviews', '!=', '[]')
              ->where('openai_result', '!=', '[]');

        $totalRecords = $query->count();

        if ($totalRecords === 0) {
            $this->info('✅ No records found that need processing.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$totalRecords} records to process");
        $this->info("⚙️  Batch size: {$batchSize}");
        $this->info("⏱️  Delay between batches: {$delay} seconds");
        $this->info("⚡ Processing: Direct (no queuing)");
        
        if ($force) {
            $this->warn('⚠️  Force mode: Processing ALL records (including already processed)');
        }
        
        if ($dryRun) {
            $this->warn('🧪 DRY RUN MODE: No actual processing will occur');
        }

        // Ask for confirmation unless it's a dry run
        if (!$dryRun && !$this->confirm('Do you want to continue?')) {
            $this->info('❌ Operation cancelled.');
            return Command::FAILURE;
        }

        // Process records in batches
        $processed = 0;
        $scraped = 0;
        $skipped = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($batchSize);
        $progressBar->setFormat('verbose');

        LoggingService::log('Starting batch processing of existing ASIN data', [
            'total_records' => $totalRecords,
            'batch_size' => $batchSize,
            'delay' => $delay,
            'force' => $force,
            'dry_run' => $dryRun,
            'processing_mode' => 'direct',
        ]);

        // Get only the first batch of records
        $asinRecords = $query->limit($batchSize)->get();

        foreach ($asinRecords as $asinData) {
            try {
                $shouldProcess = $this->shouldProcessRecord($asinData);
                
                if (!$shouldProcess['process']) {
                    $skipped++;
                    $this->line("\n🔄 Skipped {$asinData->asin}: {$shouldProcess['reason']}");
                } else {
                    if (!$dryRun) {
                        // Process directly instead of queuing
                        $this->line("\n🔄 Processing {$asinData->asin}...");
                        
                        $productService = app(AmazonProductDataService::class);
                        $result = $productService->scrapeAndSaveProductData($asinData);
                        
                        if ($result) {
                            $scraped++;
                            $this->line("✅ Successfully scraped product data for {$asinData->asin}");
                        } else {
                            $this->line("⚠️ Failed to scrape product data for {$asinData->asin}");
                        }
                    } else {
                        $this->line("\n🧪 Would process {$asinData->asin} directly");
                        $scraped++;
                    }
                }
                
                $processed++;
                $progressBar->advance();
                
                // Add delay between individual records to avoid rate limiting
                if ($delay > 0 && !$dryRun && $processed < $batchSize) {
                    $this->line("⏳ Waiting {$delay} seconds before next record...");
                    sleep($delay);
                }
                
            } catch (\Exception $e) {
                $errors++;
                $this->error("\n❌ Error processing {$asinData->asin}: " . $e->getMessage());
                
                LoggingService::log('Error in batch processing', [
                    'asin' => $asinData->asin,
                    'error' => $e->getMessage(),
                ]);
                
                $progressBar->advance();
            }
        }

        $progressBar->finish();

        // Summary
        $this->newLine(2);
        $this->info('📋 Processing Summary');
        $this->info('====================');
        $this->info("📊 Total records processed: {$processed}");
        $this->info("✅ Product data scraped: {$scraped}");
        $this->info("🔄 Records skipped: {$skipped}");
        $this->info("❌ Errors encountered: {$errors}");
        
        if ($processed < $totalRecords) {
            $remaining = $totalRecords - $processed;
            $this->newLine();
            $this->info("📊 {$remaining} records remaining to process");
            $this->info("💡 Run the command again to process the next batch");
        }

        LoggingService::log('Batch processing completed', [
            'total_processed' => $processed,
            'product_data_scraped' => $scraped,
            'records_skipped' => $skipped,
            'errors' => $errors,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Determine if a record should be processed.
     */
    private function shouldProcessRecord(AsinData $asinData): array
    {
        // Check if already has product data (unless force mode)
        if ($asinData->have_product_data && !$this->option('force')) {
            return [
                'process' => false,
                'reason' => 'Already has product data'
            ];
        }

        // Check if it has been analyzed (has reviews and OpenAI results)
        if (!$asinData->isAnalyzed()) {
            return [
                'process' => false,
                'reason' => 'Not fully analyzed yet'
            ];
        }

        // Check if recently scraped (within last 24 hours) to avoid duplicates
        if ($asinData->product_data_scraped_at && 
            $asinData->product_data_scraped_at->diffInHours(now()) < 24) {
            return [
                'process' => false,
                'reason' => 'Recently scraped (within 24 hours)'
            ];
        }

        return [
            'process' => true,
            'reason' => 'Ready for processing'
        ];
    }

    /**
     * Get statistics about records in the database.
     */
    public function getStats(): array
    {
        $total = AsinData::count();
        $analyzed = AsinData::whereNotNull('reviews')
                           ->whereNotNull('openai_result')
                           ->where('reviews', '!=', '[]')
                           ->where('openai_result', '!=', '[]')
                           ->count();
        
        $hasProductData = AsinData::where('have_product_data', true)->count();
        $needsProcessing = AsinData::where(function ($q) {
                                $q->where('have_product_data', false)
                                  ->orWhereNull('have_product_data');
                            })
                            ->whereNotNull('reviews')
                            ->whereNotNull('openai_result')
                            ->where('reviews', '!=', '[]')
                            ->where('openai_result', '!=', '[]')
                            ->count();

        return [
            'total' => $total,
            'analyzed' => $analyzed,
            'has_product_data' => $hasProductData,
            'needs_processing' => $needsProcessing,
        ];
    }
}
