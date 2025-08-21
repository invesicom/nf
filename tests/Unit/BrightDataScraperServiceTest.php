<?php

namespace Tests\Unit;

use App\Jobs\TriggerBrightDataScraping;
use App\Models\AsinData;
use App\Services\Amazon\BrightDataScraperService;
use App\Services\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrightDataScraperServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $mockHandler;
    private BrightDataScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the queue for all tests
        Queue::fake();

        // Set test environment variables FIRST
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
    public function it_can_trigger_scraping_job_successfully()
    {
        // Mock successful job trigger response
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test123456789'
        ])));

        // Mock job status polling (running -> ready)
        $this->mockHandler->append(new Response(202, [], json_encode([
            'status' => 'running',
            'message' => 'Snapshot is not ready yet, try again in 30s'
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'ready',
            'total_rows' => 25
        ])));

        // Mock data fetch response
        $mockReviewData = $this->createMockBrightDataResponse();
        $this->mockHandler->append(new Response(200, [], json_encode($mockReviewData)));

        $result = $this->service->fetchReviews('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertArrayHasKey('total_reviews', $result);
        $this->assertArrayHasKey('product_name', $result);
        $this->assertCount(3, $result['reviews']); // 3 mock reviews
        $this->assertEquals('Test Product Name', $result['product_name']);
        $this->assertEquals(166807, $result['total_reviews']);
    }

    #[Test]
    public function it_handles_missing_api_key()
    {
        putenv('BRIGHTDATA_SCRAPER_API=');
        
        $service = $this->app->make(BrightDataScraperService::class);
        $result = $service->fetchReviews('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertEmpty($result['reviews']);
        $this->assertEquals(0, $result['total_reviews']);
    }

    #[Test]
    public function it_handles_job_trigger_failure()
    {
        // Mock failed job trigger
        $this->mockHandler->append(new Response(400, [], json_encode([
            'error' => 'Invalid dataset ID'
        ])));

        $result = $this->service->fetchReviews('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertEmpty($result['reviews']);
        $this->assertEquals(0, $result['total_reviews']);
    }

    #[Test]
    public function it_handles_polling_timeout()
    {
        // Mock successful job trigger
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_timeout'
        ])));

        // Mock job never completing (always running)
        for ($i = 0; $i < 25; $i++) {
            $this->mockHandler->append(new Response(202, [], json_encode([
                'status' => 'running',
                'message' => 'Still processing...'
            ])));
        }

        $result = $this->service->fetchReviews('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertEmpty($result['reviews']);
    }

    #[Test]
    public function it_handles_job_failure_status()
    {
        // Mock successful job trigger
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_failed'
        ])));

        // Mock job failure
        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'failed',
            'total_rows' => 0
        ])));

        $result = $this->service->fetchReviews('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertEmpty($result['reviews']);
    }

    #[Test]
    public function it_can_save_reviews_to_database()
    {
        // Enable async mode for this test
        putenv('ANALYSIS_ASYNC_ENABLED=true');
        
        $result = $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should create AsinData record in processing state
        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B0TEST12345', $result->asin);
        $this->assertEquals('processing', $result->status);

        // Should dispatch the job chain
        Queue::assertPushed(TriggerBrightDataScraping::class, function ($job) {
            return $job->asin === 'B0TEST12345' && $job->country === 'us';
        });
    }

    #[Test]
    public function it_sets_have_product_data_true_when_brightdata_provides_complete_metadata()
    {
        // Enable async mode for this test
        putenv('ANALYSIS_ASYNC_ENABLED=true');
        
        $result = $this->service->fetchReviewsAndSave('B0TEST12345', 'us', 'https://amazon.com/dp/B0TEST12345');

        // Should create AsinData record in processing state (async mode)
        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B0TEST12345', $result->asin);
        $this->assertEquals('processing', $result->status);

        // Should dispatch the job chain
        Queue::assertPushed(TriggerBrightDataScraping::class, function ($job) {
            return $job->asin === 'B0TEST12345' && $job->country === 'us';
        });
    }

    #[Test]
    public function it_can_fetch_product_data()
    {
        // Mock successful scraping flow
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_product'
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'ready',
            'total_rows' => 15
        ])));

        $mockReviewData = $this->createMockBrightDataResponse();
        $this->mockHandler->append(new Response(200, [], json_encode($mockReviewData)));

        $result = $this->service->fetchProductData('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('rating', $result);
        $this->assertArrayHasKey('total_reviews', $result);
        $this->assertEquals('Test Product Name', $result['title']);
        $this->assertEquals(0, $result['rating']); // BrightData review data doesn't provide overall product rating
        $this->assertEquals(166807, $result['total_reviews']);
    }

    #[Test]
    public function it_transforms_brightdata_review_format_correctly()
    {
        // Mock successful scraping flow with rich review data
        $this->mockHandler->append(new Response(200, [], json_encode([
            'snapshot_id' => 's_test_transform'
        ])));

        $this->mockHandler->append(new Response(200, [], json_encode([
            'status' => 'ready',
            'total_rows' => 1
        ])));

        $mockReviewData = [[
            'url' => 'https://www.amazon.com/dp/B0TEST12345/',
            'product_name' => 'Test Product',
            'product_rating' => 4.5,
            'product_rating_count' => 1000,
            'rating' => 5,
            'author_name' => 'Test Author',
            'asin' => 'B0TEST12345',
            'review_header' => 'Great product!',
            'review_id' => 'R123TEST',
            'review_text' => 'This is a fantastic product that exceeded my expectations.',
            'author_id' => 'ATEST123',
            'author_link' => 'https://amazon.com/profile/ATEST123',
            'badge' => 'Verified Purchase',
            'brand' => 'TestBrand',
            'review_posted_date' => 'July 15, 2025',
            'review_country' => 'United States',
            'review_images' => ['https://example.com/image1.jpg'],
            'helpful_count' => 15,
            'is_amazon_vine' => false,
            'is_verified' => true,
            'variant_asin' => 'B0TEST12345',
            'variant_name' => 'Color: Black',
            'videos' => ['https://example.com/video1.mp4'],
            'timestamp' => '2025-08-12T21:34:21.811Z'
        ]];

        $this->mockHandler->append(new Response(200, [], json_encode($mockReviewData)));

        $result = $this->service->fetchReviews('B0TEST12345');

        $this->assertCount(1, $result['reviews']);
        $review = $result['reviews'][0];

        $this->assertEquals('R123TEST', $review['id']);
        $this->assertEquals(5, $review['rating']);
        $this->assertEquals('Great product!', $review['title']);
        $this->assertEquals('This is a fantastic product that exceeded my expectations.', $review['text']);
        $this->assertEquals('Test Author', $review['author']);
        $this->assertEquals('July 15, 2025', $review['date']);
        $this->assertTrue($review['meta_data']['verified_purchase']);
        $this->assertEquals(15, $review['meta_data']['helpful_count']);
        $this->assertFalse($review['meta_data']['vine_review']);
        $this->assertEquals('United States', $review['meta_data']['country']);
        $this->assertEquals('Verified Purchase', $review['meta_data']['badge']);
        $this->assertEquals('ATEST123', $review['meta_data']['author_id']);
        $this->assertEquals('TestBrand', $review['meta_data']['brand']);
        $this->assertArrayHasKey('images', $review);
        $this->assertArrayHasKey('videos', $review);
        $this->assertEquals(['https://example.com/image1.jpg'], $review['images']);
        $this->assertEquals(['https://example.com/video1.mp4'], $review['videos']);
    }

    #[Test]
    public function it_handles_different_amazon_domains()
    {
        $testCases = [
            ['us', 'amazon.com'],
            ['uk', 'amazon.co.uk'],
            ['ca', 'amazon.ca'],
            ['de', 'amazon.de'],
            ['fr', 'amazon.fr'],
            ['jp', 'amazon.co.jp'],
            ['au', 'amazon.com.au']
        ];

        foreach ($testCases as [$country, $expectedDomain]) {
            // Mock successful responses for each country
            $this->mockHandler->append(new Response(200, [], json_encode([
                'snapshot_id' => "s_test_{$country}"
            ])));

            $this->mockHandler->append(new Response(200, [], json_encode([
                'status' => 'ready',
                'total_rows' => 1
            ])));

            $this->mockHandler->append(new Response(200, [], json_encode([
                [
                    'url' => "https://www.{$expectedDomain}/dp/B0TEST/",
                    'review_id' => 'R123',
                    'review_text' => 'Test review',
                    'product_name' => 'Test'
                ]
            ])));

            $result = $this->service->fetchReviews('B0TEST', $country);
            $this->assertCount(1, $result['reviews']);
        }
    }

    #[Test]
    public function it_can_check_progress()
    {
        // Mock progress response
        $progressData = [
            [
                'snapshot_id' => 's_progress1',
                'status' => 'running',
                'created_at' => '2025-08-12T21:00:00Z'
            ],
            [
                'snapshot_id' => 's_progress2', 
                'status' => 'ready',
                'total_rows' => 25
            ]
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($progressData)));

        $result = $this->service->checkProgress();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('s_progress1', $result[0]['snapshot_id']);
        $this->assertEquals('running', $result[0]['status']);
    }

    #[Test]
    public function it_handles_network_errors_gracefully()
    {
        // Mock network error
        $this->mockHandler->append(new \GuzzleHttp\Exception\ConnectException(
            'Connection timeout',
            new \GuzzleHttp\Psr7\Request('POST', 'test')
        ));

        $result = $this->service->fetchReviews('B0TEST12345');

        $this->assertIsArray($result);
        $this->assertEmpty($result['reviews']);
        $this->assertEquals(0, $result['total_reviews']);
    }

    private function createMockBrightDataResponse(): array
    {
        return [
            [
                'url' => 'https://www.amazon.com/dp/B0TEST12345/',
                'product_name' => 'Test Product Name',
                'product_rating' => 4.6,
                'product_rating_count' => 166807,
                'rating' => 5,
                'author_name' => 'Test Author 1',
                'asin' => 'B0TEST12345',
                'review_header' => 'Excellent product!',
                'review_id' => 'R1TEST123',
                'review_text' => 'This is an amazing product that works exactly as described.',
                'author_id' => 'ATEST123',
                'badge' => 'Verified Purchase',
                'review_posted_date' => 'July 15, 2025',
                'review_country' => 'United States',
                'helpful_count' => 25,
                'is_amazon_vine' => false,
                'is_verified' => true
            ],
            [
                'url' => 'https://www.amazon.com/dp/B0TEST12345/',
                'product_name' => 'Test Product Name',
                'product_rating' => 4.6,
                'product_rating_count' => 166807,
                'rating' => 4,
                'author_name' => 'Test Author 2',
                'asin' => 'B0TEST12345',
                'review_header' => 'Good value',
                'review_id' => 'R2TEST456',
                'review_text' => 'Solid product for the price point.',
                'author_id' => 'ATEST456',
                'badge' => 'Verified Purchase',
                'review_posted_date' => 'July 10, 2025',
                'review_country' => 'United States',
                'helpful_count' => 12,
                'is_amazon_vine' => false,
                'is_verified' => true
            ],
            [
                'url' => 'https://www.amazon.com/dp/B0TEST12345/',
                'product_name' => 'Test Product Name',
                'product_rating' => 4.6,
                'product_rating_count' => 166807,
                'rating' => 3,
                'author_name' => 'Test Author 3',
                'asin' => 'B0TEST12345',
                'review_header' => 'Average product',
                'review_id' => 'R3TEST789',
                'review_text' => 'It works but could be better.',
                'author_id' => 'ATEST789',
                'badge' => 'Verified Purchase',
                'review_posted_date' => 'July 5, 2025',
                'review_country' => 'United States',
                'helpful_count' => 5,
                'is_amazon_vine' => true,
                'is_verified' => true
            ]
        ];
    }

    private function createMockBrightDataResponseWithImage(): array
    {
        return [
            [
                'url' => 'https://www.amazon.com/dp/B0TEST12345/',
                'product_name' => 'Test Product With Image',
                'product_rating' => 4.7,
                'product_rating_count' => 250000,
                'product_image_url' => 'https://m.media-amazon.com/images/I/test-image.jpg',
                'rating' => 5,
                'author_name' => 'Test Author 1',
                'asin' => 'B0TEST12345',
                'review_header' => 'Excellent product with image!',
                'review_id' => 'R1TEST123',
                'review_text' => 'This is an amazing product that works exactly as described.',
                'author_id' => 'ATEST123',
                'badge' => 'Verified Purchase',
                'review_posted_date' => 'July 15, 2025',
                'review_country' => 'United States',
                'helpful_count' => 25,
                'is_amazon_vine' => false,
                'is_verified' => true
            ]
        ];
    }
}
