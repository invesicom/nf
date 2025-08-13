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
                            {--dry-run : Show what would be processed without actually processing}
                            {--missing-image : Only process products missing product_image_url}
                            {--missing-description : Only process products missing product_description}
                            {--missing-any : Process products missing ANY product data (image, description, or title)}
                            {--asin= : Process only this specific ASIN}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retroactively process existing ASIN records to scrape product data (title, image, description) directly';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $batchSize = (int) $this->option('batch-size');
        $delay = (int) $this->option('delay');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $missingImage = $this->option('missing-image');
        $missingDescription = $this->option('missing-description');
        $missingAny = $this->option('missing-any');
        $singleAsin = $this->option('asin');

        $this->info('🚀 Starting ASIN Data Processing');
        $this->info('=====================================');

        // Build query for records that need processing
        $query = AsinData::query();
        
        // Handle single ASIN processing
        if ($singleAsin) {
            $query->where('asin', $singleAsin);
            $this->info("🎯 Processing single ASIN: {$singleAsin}");
        } elseif ($missingImage || $missingDescription || $missingAny) {
            // Specific field-based filtering
            $query->where(function ($q) use ($missingImage, $missingDescription, $missingAny) {
                if ($missingImage && !$missingDescription && !$missingAny) {
                    // Only missing image
                    $q->whereNull('product_image_url')
                      ->orWhere('product_image_url', '');
                } elseif ($missingDescription && !$missingImage && !$missingAny) {
                    // Only missing description
                    $q->whereNull('product_description')
                      ->orWhere('product_description', '');
                } elseif ($missingAny) {
                    // Missing any product data
                    $q->where(function ($subQ) {
                        $subQ->whereNull('product_image_url')
                             ->orWhere('product_image_url', '')
                             ->orWhereNull('product_description')
                             ->orWhere('product_description', '')
                             ->orWhereNull('product_title')
                             ->orWhere('product_title', '')
                             ->orWhere('product_title', 'Amazon.com'); // Filter out mock/default titles
                    });
                } else {
                    // Both missing image AND description
                    $q->where(function ($subQ) {
                        $subQ->whereNull('product_image_url')
                             ->orWhere('product_image_url', '');
                    })->where(function ($subQ) {
                        $subQ->whereNull('product_description')
                             ->orWhere('product_description', '');
                    });
                }
            });
        } elseif (!$force) {
            // Default behavior: only process records that don't have product data yet
            $query->where(function ($q) {
                $q->where('have_product_data', false)
                  ->orWhereNull('have_product_data');
            });
        }

        // For field-specific filtering (product data scraping), we don't need full analysis
        // For default behavior, require analysis to be complete
        if (!$singleAsin && !$missingImage && !$missingDescription && !$missingAny) {
            // Default behavior: only process records that have been analyzed (have reviews and OpenAI results)
            $query->whereNotNull('reviews')
                  ->whereNotNull('openai_result')
                  ->where('reviews', '!=', '[]')
                  ->where('openai_result', '!=', '[]');
        }
        // For product data scraping, we just need valid ASIN records
        // (no analysis requirements since we're just scraping product metadata)

        $totalRecords = $query->count();

        if ($totalRecords === 0) {
            $this->info('✅ No records found that need processing.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$totalRecords} records to process");
        $this->info("⚙️  Batch size: {$batchSize}");
        $this->info("⏱️  Delay between batches: {$delay} seconds");
        $this->info("⚡ Processing: Direct (no queuing)");
        
        // Show filtering mode
        if ($missingImage && !$missingDescription && !$missingAny) {
            $this->info("🖼️  Filter: Products missing images only");
        } elseif ($missingDescription && !$missingImage && !$missingAny) {
            $this->info("📝 Filter: Products missing descriptions only");
        } elseif ($missingAny) {
            $this->info("🔍 Filter: Products missing ANY product data (image, description, or valid title)");
        } elseif ($missingImage && $missingDescription) {
            $this->info("🖼️📝 Filter: Products missing BOTH images AND descriptions");
        } elseif (!$force) {
            $this->info("📋 Filter: Products with have_product_data=false");
        } else {
            $this->info("🌐 Filter: ALL products (force mode)");
        }
        
        if ($force) {
            $this->warn('⚠️  Force mode: Processing ALL records (including already processed)');
        }
        
        if ($dryRun) {
            $this->warn('🧪 DRY RUN MODE: No actual processing will occur');
        }

        // Skip confirmation for automated processing
        // Users can use --dry-run to preview what will be processed

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
        $missingImage = $this->option('missing-image');
        $missingDescription = $this->option('missing-description');
        $missingAny = $this->option('missing-any');
        $force = $this->option('force');

        // Check specific field requirements if field-specific options are used
        if ($missingImage || $missingDescription || $missingAny) {
            $hasImage = !empty($asinData->product_image_url);
            $hasDescription = !empty($asinData->product_description);
            $hasValidTitle = !empty($asinData->product_title) && $asinData->product_title !== 'Amazon.com';

            if ($missingImage && !$missingDescription && !$missingAny) {
                // Only checking for missing images
                if ($hasImage && !$force) {
                    return ['process' => false, 'reason' => 'Already has product image'];
                }
            } elseif ($missingDescription && !$missingImage && !$missingAny) {
                // Only checking for missing descriptions
                if ($hasDescription && !$force) {
                    return ['process' => false, 'reason' => 'Already has product description'];
                }
            } elseif ($missingAny) {
                // Check if missing ANY product data
                if ($hasImage && $hasDescription && $hasValidTitle && !$force) {
                    return ['process' => false, 'reason' => 'Has all product data'];
                }
            } else {
                // Both missing image AND description
                if ($hasImage && $hasDescription && !$force) {
                    return ['process' => false, 'reason' => 'Already has both image and description'];
                }
            }
        } else {
            // Default behavior: check if already has product data (unless force mode)
            if ($asinData->have_product_data && !$force) {
                return ['process' => false, 'reason' => 'Already has product data'];
            }
        }

        // For field-specific filtering, we don't require full analysis (just product data scraping)
        // For default behavior, require analysis to be complete
        $singleAsin = $this->option('asin');
        if (!$singleAsin && !$missingImage && !$missingDescription && !$missingAny && !$asinData->isAnalyzed()) {
            return [
                'process' => false,
                'reason' => 'Not fully analyzed yet'
            ];
        }

        // Check if recently scraped (within last 24 hours) to avoid duplicates
        if ($asinData->product_data_scraped_at && 
            $asinData->product_data_scraped_at->diffInHours(now()) < 24 &&
            !$force) {
            return [
                'process' => false,
                'reason' => 'Recently scraped (within 24 hours)'
            ];
        }

        // Determine what needs to be processed
        $needsImage = empty($asinData->product_image_url);
        $needsDescription = empty($asinData->product_description);
        $needsValidTitle = empty($asinData->product_title) || $asinData->product_title === 'Amazon.com';
        
        $reasons = [];
        if ($needsImage) $reasons[] = 'image';
        if ($needsDescription) $reasons[] = 'description';
        if ($needsValidTitle) $reasons[] = 'valid title';

        return [
            'process' => true,
            'reason' => 'Needs: ' . implode(', ', $reasons ?: ['product data'])
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
