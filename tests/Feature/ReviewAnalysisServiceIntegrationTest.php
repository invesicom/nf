<?php

namespace Tests\Feature;

use App\Models\AsinData;
use App\Services\Amazon\BrightDataScraperService;
use App\Services\ReviewAnalysisService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for the full review analysis service chain.
 * 
 * These tests exercise the complete flow:
 * ReviewAnalysisService -> ReviewFetchingService -> BrightDataScraperService
 * 
 * This addresses the gap where individual unit tests passed but the integration failed.
 */
class ReviewAnalysisServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        // Set test environment variables
        putenv('BRIGHTDATA_SCRAPER_API=test_api_key_12345');
        putenv('BRIGHTDATA_DATASET_ID=gd_test_dataset');
        putenv('AMAZON_REVIEW_SERVICE=brightdata'); // Ensure BrightData is used

        // Set up mock HTTP client for BrightData service
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        // Replace the BrightData service with our mocked version
        $this->app->singleton(BrightDataScraperService::class, function () use ($mockClient) {
            return new BrightDataScraperService(
                httpClient: $mockClient,
                apiKey: 'test_api_key_12345',
                datasetId: 'gd_test_dataset',
                baseUrl: 'https://api.brightdata.com/datasets/v3',
                pollInterval: 0,
                maxAttempts: 3
            );
        });
    }

    #[Test]
    public function it_throws_exception_when_brightdata_fails_through_full_service_chain()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock BrightData failure scenario
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_integration_failure'
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'ready',
            'records' => 0
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([])));

        $analysisService = app(ReviewAnalysisService::class);

        // This should throw an exception through the full chain
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BrightData failed to fetch reviews for ASIN: B0TEST12345');

        $analysisService->fetchReviews('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');
    }

    #[Test]
    public function it_successfully_processes_reviews_through_full_service_chain()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock successful BrightData responses
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_integration_success'
        ])));

        // Add multiple progress responses in case of polling
        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'running',
            'records' => 0
        ])));
        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'ready',
            'records' => 25
        ])));

        // Mock successful data fetch with actual reviews in raw BrightData format
        $mockReviewData = [
            [
                'review_id' => 'R123',
                'rating' => 5,
                'review_text' => 'Great product! Highly recommend.',
                'is_verified' => true,
                'product_name' => 'Test Product',
                'product_rating_count' => 100,
                'product_image_url' => 'https://example.com/image.jpg'
            ],
            [
                'review_id' => 'R124',
                'rating' => 4,
                'review_text' => 'Good quality, fast shipping.',
                'is_verified' => true
            ],
            [
                'review_id' => 'R125',
                'rating' => 1,
                'review_text' => 'Terrible fake product, avoid!',
                'is_verified' => false
            ]
        ];
        $this->mockHandler->append(new Response(200, [], json_encode($mockReviewData)));

        $analysisService = app(ReviewAnalysisService::class);
        $result = $analysisService->fetchReviews('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should successfully create AsinData through the full chain
        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B0TEST12345', $result->asin);
        $this->assertEquals('us', $result->country);
        $this->assertCount(3, $result->getReviewsArray());
        
        // Verify the reviews were properly stored
        $reviews = $result->getReviewsArray();
        $this->assertEquals('R123', $reviews[0]['id']);
        $this->assertEquals('Great product! Highly recommend.', $reviews[0]['text']);
        $this->assertTrue($reviews[0]['meta_data']['verified_purchase']);
    }

    #[Test]
    public function it_handles_analyze_with_llm_failure_when_no_reviews_available()
    {
        // Create an AsinData record with no reviews (simulates the bug scenario)
        $asinData = AsinData::create([
            'asin' => 'B0TEST12345',
            'country' => 'us',
            'reviews' => json_encode([]), // Empty reviews array
            'status' => 'pending_analysis'
        ]);

        $analysisService = app(ReviewAnalysisService::class);

        // This should throw the "No reviews available for analysis" exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No reviews available for analysis');

        $analysisService->analyzeWithLLM($asinData);
    }

    #[Test]
    public function it_prevents_empty_asin_data_creation_in_review_fetching_service()
    {
        // Simulate being in a queue worker (forces sync mode)
        $_SERVER['argv'] = ['artisan', 'queue:work', '--queue=analysis'];

        // Mock BrightData failure
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_no_creation'
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'ready',
            'records' => 0
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([])));

        $analysisService = app(ReviewAnalysisService::class);

        try {
            $analysisService->fetchReviews('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Verify no AsinData record was created by the ReviewFetchingService
            $this->assertDatabaseMissing('asin_data', [
                'asin' => 'B0TEST12345',
                'country' => 'us'
            ]);
        }
    }

    #[Test]
    public function it_uses_brightdata_service_when_amazon_review_service_is_configured()
    {
        // Verify that the service factory creates BrightData service
        $factoryService = \App\Services\Amazon\AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(BrightDataScraperService::class, $factoryService);

        // Verify that ReviewFetchingService uses the factory
        $reviewFetchingService = app(\App\Services\Amazon\ReviewFetchingService::class);
        
        // Use reflection to check the internal service
        $reflection = new \ReflectionClass($reviewFetchingService);
        $property = $reflection->getProperty('fetchService');
        $property->setAccessible(true);
        $internalService = $property->getValue($reviewFetchingService);
        
        $this->assertInstanceOf(BrightDataScraperService::class, $internalService);
    }
}
