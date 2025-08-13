<?php

namespace App\Jobs;

use App\Models\AsinData;
use App\Services\Amazon\BrightDataScraperService;
use App\Services\LoggingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TriggerBrightDataScraping implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60; // 1 minute - just to trigger the job
    public $backoff = [30, 60, 120];

    public function __construct(
        public string $asin,
        public string $country = 'us',
        private ?BrightDataScraperService $mockService = null
    ) {
        $this->onQueue('brightdata');
    }

    public function handle(): void
    {
        LoggingService::log('Triggering BrightData scraping job', [
            'asin' => $this->asin,
            'country' => $this->country
        ]);

        try {
            $brightDataService = $this->mockService ?? new BrightDataScraperService();
            
            // Build Amazon URL
            $domains = [
                'us' => 'amazon.com',
                'uk' => 'amazon.co.uk',
                'ca' => 'amazon.ca'
            ];
            $domain = $domains[$this->country] ?? $domains['us'];
            $productUrl = "https://www.{$domain}/dp/{$this->asin}/";

            // Trigger the scraping job
            $jobId = $this->triggerScrapingJob($brightDataService, [$productUrl]);
            
            if (!$jobId) {
                throw new \Exception('Failed to trigger BrightData scraping job');
            }

            LoggingService::log('BrightData job triggered successfully', [
                'asin' => $this->asin,
                'job_id' => $jobId
            ]);

            // Chain the progress checking job with a 30-second delay
            CheckBrightDataProgress::dispatch($this->asin, $this->country, $jobId)
                ->delay(now()->addSeconds(30))
                ->onQueue('brightdata');

        } catch (\Exception $e) {
            LoggingService::log('Failed to trigger BrightData scraping', [
                'asin' => $this->asin,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function triggerScrapingJob(BrightDataScraperService $service, array $urls): ?string
    {
        // Use reflection to access the private method for triggering jobs
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('triggerScrapingJob');
        $method->setAccessible(true);
        
        return $method->invoke($service, $urls);
    }

    public function failed(\Throwable $exception): void
    {
        LoggingService::log('BrightData trigger job failed permanently', [
            'asin' => $this->asin,
            'error' => $exception->getMessage()
        ]);
    }
}
