<?php

namespace Tests\Unit;

use App\Models\AsinData;
use App\Services\Amazon\BrightDataScraperService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CRITICAL: Tests for BrightData sync mode failures that weren't caught by existing tests.
 *
 * This test class addresses the gap that allowed the "No reviews available for analysis"
 * bug to pass all tests while failing in production.
 */
class BrightDataSyncModeFailureTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $mockHandler;
    private BrightDataScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Set test environment variables
        putenv('BRIGHTDATA_SCRAPER_API=test_api_key_12345');
        putenv('BRIGHTDATA_DATASET_ID=gd_test_dataset');

        // Set up mock HTTP client
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        // Create service instance with proper dependency injection for testing
        $this->service = new BrightDataScraperService(
            httpClient: $mockClient,
            apiKey: 'test_api_key_12345',
            datasetId: 'gd_test_dataset',
            baseUrl: 'https://api.brightdata.com/datasets/v3',
            pollInterval: 0, // No sleep in tests
            maxAttempts: 3   // Fewer attempts in tests
        );
    }

    #[Test]
    public function it_throws_exception_when_brightdata_returns_empty_reviews_in_sync_mode()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock BrightData API responses that return empty results
        // This simulates the exact scenario that was happening in production

        // 1. Mock successful job trigger
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_empty_results',
        ])));

        // 2. Mock job polling - job completes but with 0 results
        $this->mockHandler->append(new Response(200, [], json_encode([
            'status'  => 'ready',
            'records' => 0,  // This is the key issue - 0 results
        ])));

        // 3. Mock data fetch - returns empty array (the actual problem)
        $this->mockHandler->append(new Response(200, [], json_encode([])));

        // This should throw an exception instead of creating empty AsinData
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BrightData failed to fetch reviews for ASIN: B0TEST12345');

        $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');
    }

    #[Test]
    public function it_throws_exception_when_brightdata_api_fails_in_sync_mode()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock BrightData API failure
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_api_failure',
        ])));

        // Mock job polling failure
        $this->mockHandler->append(new Response(200, [], json_encode([
            'status'  => 'failed',
            'records' => 0,
        ])));

        // This should throw an exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BrightData failed to fetch reviews for ASIN: B0TEST12345');

        $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');
    }

    #[Test]
    public function it_throws_exception_when_brightdata_times_out_in_sync_mode()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock BrightData job trigger
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_timeout',
        ])));

        // Mock job polling - always returns "running" (simulates timeout)
        for ($i = 0; $i < 3; $i++) {
            $this->mockHandler->append(new Response(200, [], json_encode([
                'status'  => 'running',
                'records' => 0,
            ])));
        }

        // This should throw an exception after max attempts
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BrightData failed to fetch reviews for ASIN: B0TEST12345');

        $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');
    }

    #[Test]
    public function it_does_not_create_asin_data_when_brightdata_fails_in_sync_mode()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock BrightData failure scenario
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_no_data',
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status'  => 'ready',
            'records' => 0,
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([])));

        try {
            $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Verify no AsinData record was created
            $this->assertDatabaseMissing('asin_data', [
                'asin'    => 'B0TEST12345',
                'country' => 'us',
            ]);
        }
    }

    #[Test]
    public function it_successfully_creates_asin_data_when_brightdata_returns_reviews_in_sync_mode()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock successful BrightData responses
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_success',
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status'  => 'ready',
            'records' => 25,
        ])));

        // Mock successful data fetch with actual reviews
        $mockReviewData = [
            [
                'review_id'         => 'R123',
                'rating'            => 5,
                'review_text'       => 'Great product!',
                'verified_purchase' => true,
                'product_name'      => 'Test Product',
                'product_image_url' => 'https://example.com/image.jpg',
            ],
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($mockReviewData)));

        $result = $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should successfully create AsinData with reviews
        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B0TEST12345', $result->asin);
        $this->assertEquals('us', $result->country);
        $this->assertGreaterThan(0, count($result->getReviewsArray()));

        // Verify database record was created
        $this->assertDatabaseHas('asin_data', [
            'asin'    => 'B0TEST12345',
            'country' => 'us',
            'status'  => 'pending_analysis',
        ]);
    }
}
