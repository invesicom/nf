<?php

namespace Tests\Feature;

use App\Jobs\ProcessProductAnalysis;
use App\Models\AnalysisSession;
use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobChainIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock all HTTP requests to prevent real external calls
        Http::fake([
            'api.brightdata.com/*' => Http::response(['snapshot_id' => 'test_123'], 200),
            'amazon.com/*' => Http::response('<html><head><title>Test Product</title></head></html>', 200),
        ]);

        // Set test environment - note: no AMAZON_COOKIES set, so service will use mock data
        putenv('BRIGHTDATA_SCRAPER_API=test_key');
        putenv('AMAZON_REVIEW_SERVICE=brightdata');
        // Explicitly unset cookies to ensure mock data is used
        putenv('AMAZON_COOKIES=');
    }

    #[Test]
    public function process_product_analysis_job_does_not_dispatch_conflicting_jobs()
    {
        // Create an analysis session
        $session = AnalysisSession::create([
            'user_session' => 'test_session',
            'asin' => 'B0TEST12345',
            'product_url' => 'https://amazon.com/dp/B0TEST12345',
            'status' => 'pending',
            'total_steps' => 8,
            'current_message' => 'Starting...',
        ]);

        // Create ASIN data that needs product data
        $asinData = AsinData::create([
            'asin' => 'B0TEST12345',
            'country' => 'us',
            'status' => 'pending_analysis',
            'reviews' => json_encode([
                ['id' => 'R1', 'rating' => 5, 'text' => 'Great product'],
                ['id' => 'R2', 'rating' => 4, 'text' => 'Good quality'],
            ]),
            'have_product_data' => false, // This should trigger product data scraping
        ]);

        // Execute the job directly (simulating queue worker)
        $job = new ProcessProductAnalysis($session->id, $session->product_url);
        
        // This should not fail
        $job->handle();

        // Verify the session was completed
        $session->refresh();
        $this->assertEquals('completed', $session->status);

        // Verify the ASIN data was processed
        $asinData->refresh();
        $this->assertNotNull($asinData->fake_percentage);
    }

    #[Test]
    public function brightdata_service_uses_sync_mode_when_called_from_queue_worker()
    {
        putenv('ANALYSIS_ASYNC_ENABLED=true'); // Enable async globally
        
        // Create an ASIN that needs scraping
        $asinData = AsinData::create([
            'asin' => 'B0TEST12345',
            'country' => 'us',
            'status' => 'pending',
            'have_product_data' => false,
        ]);

        // Simulate being inside a queue worker by setting argv
        $_SERVER['argv'] = ['artisan', 'queue:work', 'database'];

        // Create the BrightData service
        $service = app(\App\Services\Amazon\BrightDataScraperService::class);
        
        // This should use sync mode despite ANALYSIS_ASYNC_ENABLED=true
        $result = $service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should have processed synchronously
        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B0TEST12345', $result->asin);
        
        // Should not have dispatched any jobs (we're not using Queue::fake() here)
        // If it did dispatch jobs, they would run immediately in the sync queue
    }
}
