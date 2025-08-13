<?php

namespace App\Jobs;

use App\Models\AnalysisSession;
use App\Models\AsinData;
use App\Services\LoggingService;
use App\Services\ReviewAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessProductAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 600; // 10 minutes - sufficient for BrightData processing
    public $backoff = [30, 60, 120]; // Backoff delays for retries

    public function __construct(
        private string $sessionId,
        private string $productUrl
    ) {
        // Use dedicated analysis queue
        $this->onQueue('analysis');
    }

    public function handle(): void
    {
        $session = AnalysisSession::find($this->sessionId);
        
        if (!$session) {
            Log::error('Analysis session not found', ['session_id' => $this->sessionId]);
            return;
        }

        try {
            $session->markAsProcessing();
            
            LoggingService::log('Starting async product analysis', [
                'session_id' => $this->sessionId,
                'asin' => $session->asin,
                'attempt' => $this->attempts(),
            ]);

            // Step 1: Captcha already validated in controller
            $session->updateProgress(1, 12, 'Starting analysis...');

            // Step 2: Check if product exists in database
            $session->updateProgress(2, 25, 'Checking product database...');
            $analysisService = app(ReviewAnalysisService::class);
            $productInfo = $analysisService->checkProductExists($this->productUrl);
            
            $asinData = $productInfo['asin_data'];

            // Step 3: Fetch reviews if needed
            if ($productInfo['needs_fetching']) {
                $session->updateProgress(3, 52, 'Gathering review information...');
                $asinData = $analysisService->fetchReviews(
                    $productInfo['asin'],
                    $productInfo['country'],
                    $productInfo['product_url']
                );
            }

            // Step 4: Analyze with OpenAI if needed
            if ($productInfo['needs_openai']) {
                $session->updateProgress(4, 70, 'Analyzing reviews with AI...');
                $asinData = $analysisService->analyzeWithOpenAI($asinData);
            }

            // Step 5: Calculate final metrics
            $session->updateProgress(5, 85, 'Computing authenticity metrics...');
            $analysisResult = $analysisService->calculateFinalMetrics($asinData);

            // Step 6: Execute product data scraping as part of analysis flow (user waits for complete result)
            if (!$asinData->have_product_data) {
                $session->updateProgress(6, 92, 'Fetching product information...');
                
                // Execute product scraping synchronously as part of the analysis process
                $scrapeJob = new ScrapeAmazonProductData($asinData);
                $scrapeJob->handle();
                
                // Refresh the model to get updated product data
                $asinData = $asinData->fresh();
                
                LoggingService::log('Product scraping completed as part of analysis', [
                    'asin' => $asinData->asin,
                    'session_id' => $this->sessionId,
                    'have_product_data' => $asinData->have_product_data,
                ]);
            } else {
                // Skip product data step if already available
                $session->updateProgress(6, 92, 'Product information already available...');
            }

            // Step 7: Complete analysis with full product data available
            $session->updateProgress(7, 98, 'Generating final report...');
            
            // Prepare final result
            $redirectUrl = $this->determineRedirectUrl($asinData);
            
            LoggingService::log('Async analysis completed successfully', [
                'asin' => $asinData->asin,
                'redirect_url' => $redirectUrl,
            ]);
            
            $finalResult = [
                'success' => true,
                'asin_data' => $asinData->fresh(),
                'analysis_result' => $analysisResult,
                'redirect_url' => $redirectUrl,
            ];

            // Step 8: Mark as completed (100%)
            $session->markAsCompleted($finalResult);
            
            LoggingService::log('Async product analysis completed successfully', [
                'session_id' => $this->sessionId,
                'asin' => $session->asin,
                'total_time' => now()->diffInSeconds($session->started_at),
            ]);

        } catch (\Exception $e) {
            $this->handleFailure($session, $e);
        }
    }



    private function determineRedirectUrl(AsinData $asinData): ?string
    {
        // Async mode: Always redirect to product page since we wait for complete analysis + product data
        // By this point, product scraping has completed as part of the analysis flow
        
        if ($asinData->slug) {
            return route('amazon.product.show.slug', [
                'asin' => $asinData->asin,
                'slug' => $asinData->slug
            ]);
        }

        return route('amazon.product.show', ['asin' => $asinData->asin]);
    }

    private function handleFailure(AnalysisSession $session, \Exception $e): void
    {
        $userMessage = LoggingService::handleException($e);
        $session->markAsFailed($userMessage);

        LoggingService::log('Async product analysis failed', [
            'session_id' => $this->sessionId,
            'asin' => $session->asin,
            'error' => $e->getMessage(),
            'attempt' => $this->attempts(),
        ]);

        // Re-throw for retry mechanism if not final attempt
        if ($this->attempts() < $this->tries) {
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $session = AnalysisSession::find($this->sessionId);
        
        if ($session && !$session->isFailed()) {
            $userMessage = LoggingService::handleException($exception);
            $session->markAsFailed($userMessage);
        }

        Log::error('Product analysis job failed permanently', [
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
} 