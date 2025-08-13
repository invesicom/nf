<?php

namespace Tests\Feature;

use App\Jobs\TriggerBrightDataScraping;
use App\Services\Amazon\BrightDataScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobChainIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function brightdata_service_dispatches_async_job_chain_when_enabled()
    {
        Queue::fake();
        
        putenv('ANALYSIS_ASYNC_ENABLED=true');
        putenv('BRIGHTDATA_SCRAPER_API=test_key');
        
        // Override the environment check to force async mode for this test
        // by not running in console mode
        $this->app['env'] = 'production'; // Force non-testing environment
        
        $service = new BrightDataScraperService();
        $result = $service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should create AsinData and dispatch job
        $this->assertEquals('B0TEST12345', $result->asin);

        // Should dispatch the trigger job
        Queue::assertPushed(TriggerBrightDataScraping::class, function ($job) {
            return $job->asin === 'B0TEST12345' && $job->country === 'us';
        });
    }

    #[Test]
    public function brightdata_service_uses_sync_mode_when_in_queue_worker()
    {
        Queue::fake();
        
        putenv('ANALYSIS_ASYNC_ENABLED=true'); // Enable async globally
        
        // Simulate being in testing environment (forces sync mode)
        $service = new BrightDataScraperService();
        $result = $service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should have processed synchronously (no jobs dispatched)
        $this->assertEquals('B0TEST12345', $result->asin);
        Queue::assertNothingPushed();
    }

    #[Test] 
    public function brightdata_service_uses_sync_mode_when_async_disabled()
    {
        Queue::fake();
        
        putenv('ANALYSIS_ASYNC_ENABLED=false');
        
        $service = new BrightDataScraperService();
        $result = $service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should have processed synchronously
        $this->assertEquals('B0TEST12345', $result->asin);
        Queue::assertNothingPushed();
    }
}
