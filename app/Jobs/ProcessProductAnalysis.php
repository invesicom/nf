<?php

namespace App\Jobs;

use App\Models\AnalysisSession;
use App\Models\AsinData;
use App\Services\CaptchaService;
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
    public $timeout = 300; // 5 minutes total timeout
    public $backoff = [30, 60, 120]; // Backoff delays for retries

    public function __construct(
        private string $sessionId,
        private string $productUrl,
        private array $captchaData = []
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

            // Step 1: Validate captcha if needed
            $session->updateProgress(1, 12, 'Validating request...');
            $this->validateCaptcha();

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

            // Step 6: Queue product data scraping if needed (async)
            if (!$asinData->have_product_data) {
                ScrapeAmazonProductData::dispatch($asinData)->onQueue('product-scraping');
            }

            // Step 7: Complete
            $session->updateProgress(6, 95, 'Generating final report...');
            
            // Prepare final result
            $finalResult = [
                'success' => true,
                'asin_data' => $asinData->fresh(),
                'analysis_result' => $analysisResult,
                'redirect_url' => $this->determineRedirectUrl($asinData),
            ];

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

    private function validateCaptcha(): void
    {
        if (app()->environment(['local', 'testing']) || empty($this->captchaData)) {
            return;
        }

        $captchaService = app(CaptchaService::class);
        $provider = $captchaService->getProvider();

        $response = null;
        if ($provider === 'recaptcha' && !empty($this->captchaData['g_recaptcha_response'])) {
            $response = $this->captchaData['g_recaptcha_response'];
        } elseif ($provider === 'hcaptcha' && !empty($this->captchaData['h_captcha_response'])) {
            $response = $this->captchaData['h_captcha_response'];
        }

        if (!$response || !$captchaService->verify($response)) {
            throw new \Exception('Captcha verification failed');
        }
    }

    private function determineRedirectUrl(AsinData $asinData): ?string
    {
        if (!$asinData->have_product_data) {
            return null;
        }

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