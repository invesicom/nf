<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AsinData;
use App\Services\Amazon\AmazonScrapingService;
use App\Services\ReviewAnalysisService;
use App\Services\LoggingService;

class ForceRescrapeDeduplicated extends Command
{
    protected $signature = 'force:rescrape-deduplicated 
                            {--count=10 : Number of recent products to re-scrape}
                            {--min-reviews=5 : Only re-scrape products with fewer than X reviews}
                            {--dry-run : Show what would be re-scraped without doing it}
                            {--with-analysis : Re-run LLM analysis after scraping}
                            {--asin= : Force re-scrape specific ASIN only}';
    
    protected $description = 'Force re-scraping of recently deduplicated/fixed products to get fresh data';

    private AmazonScrapingService $scrapingService;
    private ReviewAnalysisService $analysisService;

    public function __construct(AmazonScrapingService $scrapingService, ReviewAnalysisService $analysisService)
    {
        parent::__construct();
        $this->scrapingService = $scrapingService;
        $this->analysisService = $analysisService;
    }

    public function handle()
    {
        $count = (int) $this->option('count');
        $minReviews = (int) $this->option('min-reviews');
        $isDryRun = $this->option('dry-run');
        $withAnalysis = $this->option('with-analysis');
        $specificAsin = $this->option('asin');
        
        $this->info('🔄 FORCE RE-SCRAPING DEDUPLICATED PRODUCTS');
        $this->info('===========================================');
        $this->newLine();

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No actual scraping will be performed');
            $this->newLine();
        }

        // Find candidates for re-scraping
        $query = AsinData::whereNotNull('reviews')
            ->whereNotNull('total_reviews_on_amazon')
            ->where('total_reviews_on_amazon', '>', 0);

        if ($specificAsin) {
            $query->where('asin', $specificAsin);
            $this->info("🎯 Processing specific ASIN: {$specificAsin}");
        } else {
            // Look for recently updated products (likely fixed by deduplication)
            $query->where('updated_at', '>=', now()->subDays(7))
                ->orderBy('updated_at', 'desc')
                ->limit($count);
            $this->info("📊 Processing up to {$count} recently updated products");
        }

        $candidates = $query->get();
        $this->info("📦 Found {$candidates->count()} candidate products");
        $this->newLine();

        // Filter candidates based on review count
        $targets = [];
        foreach ($candidates as $product) {
            $reviews = $product->getReviewsArray();
            $currentCount = count($reviews);
            
            if ($currentCount < $minReviews || $specificAsin) {
                $targets[] = [
                    'product' => $product,
                    'current_reviews' => $currentCount,
                    'amazon_total' => $product->total_reviews_on_amazon,
                    'potential_gain' => $product->total_reviews_on_amazon - $currentCount
                ];
            }
        }

        if (empty($targets)) {
            $this->info("✅ No products found that need re-scraping");
            $this->info("   (All have >= {$minReviews} reviews or no significant potential gain)");
            return Command::SUCCESS;
        }

        // Sort by potential gain (highest first)
        usort($targets, function($a, $b) {
            return $b['potential_gain'] - $a['potential_gain'];
        });

        $this->info("🎯 PRODUCTS TO RE-SCRAPE:");
        $this->info("========================");
        
        $tableData = [];
        foreach ($targets as $target) {
            $tableData[] = [
                $target['product']->asin,
                $target['current_reviews'],
                $target['amazon_total'],
                '+' . $target['potential_gain'],
                $target['product']->updated_at->format('M j, H:i')
            ];
        }
        
        $this->table(['ASIN', 'Current', 'Amazon Total', 'Potential Gain', 'Last Updated'], $tableData);
        $this->newLine();

        if ($isDryRun) {
            $this->info('💡 Run without --dry-run to perform actual re-scraping');
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$specificAsin && !$this->confirm("🚀 Proceed with re-scraping " . count($targets) . " products?")) {
            $this->info("❌ Operation cancelled");
            return Command::SUCCESS;
        }

        // Perform re-scraping
        $this->info('🔄 Starting re-scraping process...');
        $progressBar = $this->output->createProgressBar(count($targets));
        $progressBar->start();

        $successful = 0;
        $failed = 0;
        $results = [];

        foreach ($targets as $target) {
            $product = $target['product'];
            $originalCount = $target['current_reviews'];
            
            try {
                // Generate product URL
                $productUrl = "https://www.amazon.com/dp/{$product->asin}";
                
                // Backup original data
                $originalReviews = $product->reviews;
                
                // Perform fresh scraping
                $freshProduct = $this->scrapingService->fetchReviewsAndSave(
                    $product->asin, 
                    $product->country ?? 'com', 
                    $productUrl
                );
                
                if ($freshProduct) {
                    $newReviews = $freshProduct->getReviewsArray();
                    $newCount = count($newReviews);
                    $gain = $newCount - $originalCount;
                    
                    $results[] = [
                        'asin' => $product->asin,
                        'original' => $originalCount,
                        'new' => $newCount,
                        'gain' => $gain,
                        'status' => '✅ SUCCESS'
                    ];
                    
                    // Re-run analysis if requested
                    if ($withAnalysis && $newCount > 0) {
                        try {
                            $analysisResult = $this->analysisService->analyzeWithOpenAI($product->asin, $newReviews);
                            if ($analysisResult) {
                                $freshProduct->openai_result = json_encode($analysisResult);
                                $freshProduct->fake_percentage = $analysisResult['fake_percentage'] ?? null;
                                $freshProduct->grade = $analysisResult['grade'] ?? null;
                                $freshProduct->adjusted_rating = $analysisResult['adjusted_rating'] ?? null;
                                $freshProduct->detailed_analysis = $analysisResult['detailed_analysis'] ?? null;
                                $freshProduct->fake_review_examples = $analysisResult['fake_review_examples'] ?? null;
                                $freshProduct->save();
                            }
                        } catch (\Exception $e) {
                            // Analysis failed but scraping succeeded
                            LoggingService::log("Re-analysis failed after re-scraping", [
                                'asin' => $product->asin,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    // Log the successful re-scraping
                    LoggingService::log("Forced re-scraping completed", [
                        'asin' => $product->asin,
                        'original_reviews' => $originalCount,
                        'new_reviews' => $newCount,
                        'reviews_gained' => $gain,
                        'analysis_updated' => $withAnalysis,
                        'reason' => 'Force re-scrape after deduplication'
                    ]);
                    
                    $successful++;
                } else {
                    throw new \Exception("Scraping returned null result");
                }
                
            } catch (\Exception $e) {
                $results[] = [
                    'asin' => $product->asin,
                    'original' => $originalCount,
                    'new' => $originalCount,
                    'gain' => 0,
                    'status' => '❌ FAILED: ' . substr($e->getMessage(), 0, 30)
                ];
                
                $failed++;
                
                LoggingService::log("Forced re-scraping failed", [
                    'asin' => $product->asin,
                    'error' => $e->getMessage(),
                    'original_reviews' => $originalCount
                ]);
            }
            
            $progressBar->advance();
            
            // Rate limiting - pause between requests
            if (!$specificAsin) {
                sleep(2);
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->info('📊 RE-SCRAPING RESULTS:');
        $this->info('======================');
        
        $resultTableData = [];
        foreach ($results as $result) {
            $resultTableData[] = [
                $result['asin'],
                $result['original'],
                $result['new'],
                $result['gain'] > 0 ? '+' . $result['gain'] : $result['gain'],
                $result['status']
            ];
        }
        
        $this->table(['ASIN', 'Original', 'New', 'Gain', 'Status'], $resultTableData);
        $this->newLine();

        // Summary
        $totalGain = array_sum(array_column($results, 'gain'));
        $this->info("🎯 SUMMARY:");
        $this->info("==========");
        $this->info("✅ Successful: {$successful}");
        if ($failed > 0) $this->warn("❌ Failed: {$failed}");
        $this->info("📈 Total reviews gained: {$totalGain}");
        if ($withAnalysis) {
            $this->info("🤖 Analysis updated for successful re-scrapes");
        }

        return Command::SUCCESS;
    }
}
