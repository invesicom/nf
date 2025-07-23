<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LoggingService;
use App\Services\ReviewAnalysisService;
use Illuminate\Console\Command;

class ReprocessHistoricalGrading extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'grading:reprocess-historical 
                            {--batch-size=50 : Number of records to process in each batch}
                            {--force : Reprocess all records, even those with existing grades}
                            {--dry-run : Show what would be processed without actually processing}
                            {--asin= : Process only a specific ASIN}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reprocess historical ASIN data to apply current grading calculations';

    private ReviewAnalysisService $analysisService;

    /**
     * Create a new command instance.
     */
    public function __construct(ReviewAnalysisService $analysisService)
    {
        parent::__construct();
        $this->analysisService = $analysisService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $specificAsin = $this->option('asin');

        $this->info('🔄 Reprocessing Historical Grading');
        $this->info('================================');

        // Build query for records that need reprocessing
        $query = AsinData::query();
        
        // Only process records that have OpenAI analysis
        $query->whereNotNull('openai_result')
              ->where('openai_result', '!=', '[]')
              ->where('openai_result', '!=', '""');

        // Filter by specific ASIN if provided
        if ($specificAsin) {
            $query->where('asin', $specificAsin);
        }

        // Only process records without grades unless force mode
        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('fake_percentage')
                  ->orWhereNull('grade')
                  ->orWhereNull('amazon_rating')
                  ->orWhereNull('adjusted_rating');
            });
        }

        $totalRecords = $query->count();

        if ($totalRecords === 0) {
            if ($specificAsin) {
                $this->info("✅ No records found for ASIN: {$specificAsin}");
            } else {
                $this->info('✅ No records found that need grading reprocessing.');
            }
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$totalRecords} records to reprocess");
        $this->info("⚙️  Batch size: {$batchSize}");
        
        if ($specificAsin) {
            $this->info("🎯 Processing specific ASIN: {$specificAsin}");
        }
        
        if ($force) {
            $this->warn('⚠️  Force mode: Reprocessing ALL records (including those with existing grades)');
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
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        LoggingService::log('Starting historical grading reprocessing', [
            'total_records' => $totalRecords,
            'batch_size' => $batchSize,
            'force' => $force,
            'dry_run' => $dryRun,
            'specific_asin' => $specificAsin,
        ]);

        // Process in chunks to avoid memory issues
        $query->chunk($batchSize, function ($asinRecords) use (&$processed, &$updated, &$skipped, &$errors, $dryRun, $force) {
            foreach ($asinRecords as $asinData) {
                try {
                    $shouldProcess = $this->shouldProcessRecord($asinData, $force);
                    
                    if (!$shouldProcess['process']) {
                        $skipped++;
                        $this->line("🔄 Skipped {$asinData->asin}: {$shouldProcess['reason']}");
                    } else {
                        if (!$dryRun) {
                            // Reprocess the grading using current logic
                            $this->line("🔄 Reprocessing {$asinData->asin}...");
                            
                            $originalData = [
                                'fake_percentage' => $asinData->fake_percentage,
                                'grade' => $asinData->grade,
                                'amazon_rating' => $asinData->amazon_rating,
                                'adjusted_rating' => $asinData->adjusted_rating,
                            ];
                            
                            $result = $this->analysisService->calculateFinalMetrics($asinData);
                            
                            $this->line("  ✅ Updated grading for {$asinData->asin}");
                            $this->line("     📊 Fake: {$originalData['fake_percentage']}% → {$result['fake_percentage']}%");
                            $this->line("     📝 Grade: {$originalData['grade']} → {$result['grade']}");
                            $this->line("     ⭐ Amazon: {$originalData['amazon_rating']} → {$result['amazon_rating']}");
                            $this->line("     🔧 Adjusted: {$originalData['adjusted_rating']} → {$result['adjusted_rating']}");
                            
                            $updated++;
                        } else {
                            $this->line("🧪 Would reprocess grading for {$asinData->asin}");
                            $updated++;
                        }
                    }
                    
                    $processed++;
                    
                } catch (\Exception $e) {
                    $errors++;
                    $this->error("❌ Error processing {$asinData->asin}: " . $e->getMessage());
                    
                    LoggingService::log('Error in grading reprocessing', [
                        'asin' => $asinData->asin,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        });

        // Summary
        $this->newLine();
        $this->info('📋 Reprocessing Summary');
        $this->info('======================');
        $this->info("📊 Total records processed: {$processed}");
        $this->info("✅ Records updated: {$updated}");
        $this->info("🔄 Records skipped: {$skipped}");
        $this->info("❌ Errors encountered: {$errors}");

        LoggingService::log('Historical grading reprocessing completed', [
            'total_processed' => $processed,
            'records_updated' => $updated,
            'records_skipped' => $skipped,
            'errors' => $errors,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Determine if a record should be processed.
     */
    private function shouldProcessRecord(AsinData $asinData, bool $force): array
    {
        // Check if it has OpenAI analysis data
        if (!$asinData->openai_result) {
            return [
                'process' => false,
                'reason' => 'No OpenAI analysis data'
            ];
        }

        // Check if it has reviews
        $reviews = $asinData->getReviewsArray();
        if (empty($reviews)) {
            return [
                'process' => false,
                'reason' => 'No reviews available'
            ];
        }

        // Check if already has complete grading (unless force mode)
        if (!$force && 
            $asinData->fake_percentage !== null && 
            $asinData->grade !== null && 
            $asinData->amazon_rating !== null && 
            $asinData->adjusted_rating !== null) {
            return [
                'process' => false,
                'reason' => 'Already has complete grading'
            ];
        }

        return [
            'process' => true,
            'reason' => 'Ready for grading reprocessing'
        ];
    }

    /**
     * Get statistics about records in the database.
     */
    public function getStats(): array
    {
        $total = AsinData::count();
        $hasOpenAI = AsinData::whereNotNull('openai_result')
                           ->where('openai_result', '!=', '[]')
                           ->where('openai_result', '!=', '""')
                           ->count();
        
        $hasCompleteGrading = AsinData::whereNotNull('fake_percentage')
                                    ->whereNotNull('grade')
                                    ->whereNotNull('amazon_rating')
                                    ->whereNotNull('adjusted_rating')
                                    ->count();
                                    
        $needsReprocessing = AsinData::whereNotNull('openai_result')
                                   ->where('openai_result', '!=', '[]')
                                   ->where('openai_result', '!=', '""')
                                   ->where(function ($q) {
                                       $q->whereNull('fake_percentage')
                                         ->orWhereNull('grade')
                                         ->orWhereNull('amazon_rating')
                                         ->orWhereNull('adjusted_rating');
                                   })
                                   ->count();

        return [
            'total' => $total,
            'has_openai' => $hasOpenAI,
            'has_complete_grading' => $hasCompleteGrading,
            'needs_reprocessing' => $needsReprocessing,
        ];
    }
} 