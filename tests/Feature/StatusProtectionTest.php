<?php

namespace Tests\Feature;

use App\Jobs\ProcessBrightDataResults;
use App\Models\AsinData;
use App\Services\Amazon\BrightDataScraperService;
use App\Services\ExtensionReviewService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests that verify background jobs and services do NOT overwrite
 * a completed analysis status. This prevents status "flapping" where
 * late-running background jobs reset a completed analysis.
 */
class StatusProtectionTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $mockHandler;
    private BrightDataScraperService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        $this->service = new BrightDataScraperService(
            httpClient: $mockClient,
            apiKey: 'test_api_key',
            datasetId: 'test_dataset',
            baseUrl: 'https://api.brightdata.com/datasets/v3',
            pollInterval: 0,
            maxAttempts: 3
        );
    }

    #[Test]
    public function process_brightdata_results_does_not_overwrite_completed_analysis(): void
    {
        // Create a completed analysis
        $asinData = AsinData::factory()->create([
            'asin' => 'B0COMPLETE1',
            'country' => 'us',
            'status' => 'completed',
            'fake_percentage' => 25.0,
            'grade' => 'B',
            'explanation' => 'Original analysis',
            'reviews' => json_encode([['id' => 1, 'text' => 'Original review']]),
            'product_title' => 'Original Product Title',
        ]);

        // Mock BrightData returning new data
        $mockData = $this->createMockBrightDataResponse();
        $this->mockHandler->append(new Response(200, [], json_encode($mockData)));

        // Process results job runs (simulating late background job)
        $job = new ProcessBrightDataResults('B0COMPLETE1', 'us', 's_test_job', $this->service);
        $job->handle();

        // Verify status was NOT changed
        $asinData->refresh();
        $this->assertEquals('completed', $asinData->status);
        $this->assertEquals('B', $asinData->grade);
        $this->assertEquals(25.0, $asinData->fake_percentage);
        $this->assertEquals('Original analysis', $asinData->explanation);
    }

    #[Test]
    public function extension_review_service_does_not_overwrite_completed_analysis(): void
    {
        // Create a completed analysis
        $asinData = AsinData::factory()->create([
            'asin' => 'B0COMPLETE2',
            'country' => 'us',
            'status' => 'completed',
            'fake_percentage' => 30.0,
            'grade' => 'C',
            'explanation' => 'Original completed analysis',
            'reviews' => json_encode([['id' => 1, 'text' => 'Original review']]),
            'product_title' => 'Original Product',
        ]);

        $service = app(ExtensionReviewService::class);

        // Attempt to process new extension data
        $newData = [
            'asin' => 'B0COMPLETE2',
            'country' => 'us',
            'product_url' => 'https://www.amazon.com/dp/B0COMPLETE2',
            'product_info' => [
                'title' => 'New Product Title',
                'image_url' => 'https://example.com/new-image.jpg',
                'rating' => 4.8,
                'total_reviews_on_amazon' => 500,
            ],
            'reviews' => [
                ['id' => '2', 'text' => 'New review from extension', 'rating' => 5],
            ],
            'extension_version' => '1.0.0',
        ];

        $result = $service->processExtensionData($newData);

        // Verify status was NOT changed and original analysis preserved
        $asinData->refresh();
        $this->assertEquals('completed', $asinData->status);
        $this->assertEquals('C', $asinData->grade);
        $this->assertEquals(30.0, $asinData->fake_percentage);
        $this->assertEquals('Original completed analysis', $asinData->explanation);
    }

    #[Test]
    public function submit_reviews_endpoint_returns_existing_analysis_for_completed_product(): void
    {
        // Create a completed analysis
        $asinData = AsinData::factory()->create([
            'asin' => 'B0COMPL003',
            'country' => 'us',
            'status' => 'completed',
            'fake_percentage' => 20.0,
            'grade' => 'B',
            'explanation' => 'Existing analysis',
            'reviews' => json_encode([['id' => 1, 'text' => 'Existing review']]),
            'product_title' => 'Existing Product',
            'product_image_url' => 'https://example.com/image.jpg',
            'amazon_rating' => 4.5,
        ]);

        // Submit new reviews via API with proper structure
        $response = $this->postJson('/api/extension/submit-reviews', [
            'asin' => 'B0COMPL003',
            'country' => 'us',
            'product_url' => 'https://www.amazon.com/dp/B0COMPL003',
            'extraction_timestamp' => now()->format('Y-m-d\TH:i:s.v\Z'),
            'extension_version' => '1.0.0',
            'product_info' => [
                'title' => 'New Title (should be ignored)',
                'description' => 'New description',
                'image_url' => 'https://example.com/new-image.jpg',
                'amazon_rating' => 4.8,
                'total_reviews_on_amazon' => 500,
            ],
            'reviews' => [], // Empty reviews to test existing analysis return
        ]);

        // Note: With empty reviews and existing completed analysis, endpoint should return existing
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'asin' => 'B0COMPL003',
                'country' => 'us',
                'analysis_complete' => true,
                'already_analyzed' => true,
                'grade' => 'B',
            ]);

        // Verify database was NOT modified
        $asinData->refresh();
        $this->assertEquals('completed', $asinData->status);
        $this->assertEquals('B', $asinData->grade);
        $this->assertEquals('Existing Product', $asinData->product_title);
    }

    #[Test]
    public function brightdata_results_can_update_non_completed_analysis(): void
    {
        // Create an in-progress analysis
        $asinData = AsinData::factory()->create([
            'asin' => 'B0PENDING01',
            'country' => 'us',
            'status' => 'processing',
            'fake_percentage' => null,
            'grade' => null,
        ]);

        // Mock BrightData returning data
        $mockData = $this->createMockBrightDataResponse('B0PENDING01');
        $this->mockHandler->append(new Response(200, [], json_encode($mockData)));

        // Process results
        $job = new ProcessBrightDataResults('B0PENDING01', 'us', 's_test_job', $this->service);
        $job->handle();

        // Verify status WAS updated (since it wasn't completed)
        $asinData->refresh();
        $this->assertEquals('pending_analysis', $asinData->status);
        $this->assertEquals('Test Product Name', $asinData->product_title);
    }

    #[Test]
    public function extension_data_can_update_non_completed_analysis(): void
    {
        // Create a fetched (not completed) record
        $asinData = AsinData::factory()->create([
            'asin' => 'B0FETCHED01',
            'country' => 'us',
            'status' => 'fetched',
            'fake_percentage' => null,
            'grade' => null,
            'product_title' => 'Old Title',
        ]);

        $service = app(ExtensionReviewService::class);

        $newData = [
            'asin' => 'B0FETCHED01',
            'country' => 'us',
            'product_url' => 'https://www.amazon.com/dp/B0FETCHED01',
            'extraction_timestamp' => now()->format('Y-m-d\TH:i:s.v\Z'),
            'product_info' => [
                'title' => 'Updated Title',
                'image_url' => 'https://example.com/new-image.jpg',
                'rating' => 4.2,
                'total_reviews_on_amazon' => 100,
            ],
            'reviews' => [
                [
                    'review_id' => 'R1TEST12345',
                    'author' => 'Test Author',
                    'title' => 'Great Product',
                    'content' => 'This is a review.',
                    'rating' => 4,
                    'date' => '2025-01-01',
                    'verified_purchase' => true,
                    'vine_customer' => false,
                    'helpful_votes' => 5,
                    'extraction_index' => 1,
                ],
            ],
            'extension_version' => '1.0.0',
        ];

        $result = $service->processExtensionData($newData);

        // Verify data WAS updated
        $asinData->refresh();
        $this->assertEquals('fetched', $asinData->status);
        $this->assertEquals('Updated Title', $asinData->product_title);
    }

    private function createMockBrightDataResponse(string $asin = 'B0COMPLETE1'): array
    {
        return [
            [
                'url' => "https://www.amazon.com/dp/{$asin}/",
                'product_name' => 'Test Product Name',
                'product_rating' => 4.6,
                'product_rating_count' => 1000,
                'rating' => 5,
                'author_name' => 'Test Author',
                'asin' => $asin,
                'review_header' => 'Great product',
                'review_id' => 'R1TEST123',
                'review_text' => 'This product is excellent.',
                'author_id' => 'ATEST123',
                'badge' => 'Verified Purchase',
                'review_posted_date' => 'July 15, 2025',
                'review_country' => 'United States',
                'helpful_count' => 10,
                'is_amazon_vine' => false,
                'is_verified' => true,
            ],
        ];
    }
}

