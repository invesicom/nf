<?php

namespace Tests\Unit;

use App\Models\AsinData;
use App\Services\Amazon\AmazonScrapingService;
use App\Services\AlertService;
use App\Services\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AmazonScrapingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $mockHandler;
    private AmazonScrapingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up mock HTTP client
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        $mockClient = new Client(['handler' => $handlerStack]);

        // Create service instance and inject mock client
        $this->service = new AmazonScrapingService();
        
        // Set the mock HTTP client
        $this->service->setHttpClient($mockClient);

        // Set test environment variables
        putenv('AMAZON_COOKIES=session-id=test123; session-token=test456');
    }

    public function test_fetch_reviews_and_save_success()
    {
        // Mock product page response
        $productHtml = $this->createMockProductHtml('Test Product Title');
        $this->mockHandler->append(new Response(200, [], $productHtml));

        // Mock review URL pattern testing (3 patterns, first one works)
        $reviewsHtml = $this->createMockReviewsHtml();
        $this->mockHandler->append(new Response(200, [], $reviewsHtml));

        // Mock the actual reviews page request
        $this->mockHandler->append(new Response(200, [], $reviewsHtml));

        $result = $this->service->fetchReviewsAndSave('B08N5WRWNW', 'us', 'https://amazon.com/dp/B08N5WRWNW');

        $this->assertInstanceOf(AsinData::class, $result);
        $this->assertEquals('B08N5WRWNW', $result->asin);
        $this->assertEquals('us', $result->country);
        $this->assertEquals('Test Product Title', $result->product_description);
        $this->assertNotNull($result->reviews);
        
        $reviews = json_decode($result->reviews, true);
        $this->assertIsArray($reviews);
        $this->assertGreaterThan(0, count($reviews));
    }

    public function test_fetch_reviews_and_save_product_not_exists()
    {
        // Mock empty product page response
        $this->mockHandler->append(new Response(404, [], 'Not Found'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Unable to fetch product reviews/');

        $this->service->fetchReviewsAndSave('B08N5WRWNW', 'us', 'https://amazon.com/dp/B08N5WRWNW');
    }

    public function test_fetch_reviews_success()
    {
        // Mock product page response
        $productHtml = $this->createMockProductHtml('Test Product');
        $this->mockHandler->append(new Response(200, [], $productHtml));

        // Mock review URL pattern testing (3 patterns, first one works)
        $reviewsHtml = $this->createMockReviewsHtml();
        $this->mockHandler->append(new Response(200, [], $reviewsHtml));

        // Mock the actual reviews page request
        $this->mockHandler->append(new Response(200, [], $reviewsHtml));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('total_reviews', $result);
        $this->assertEquals('Test Product', $result['description']);
        $this->assertGreaterThan(0, count($result['reviews']));
    }

    public function test_fetch_reviews_invalid_asin_format()
    {
        $result = $this->service->fetchReviews('INVALID123', 'us');
        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_product_page_error()
    {
        // Mock product page error
        $this->mockHandler->append(new Response(500, [], 'Server Error'));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');
        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_no_reviews_found()
    {
        // Mock product page response
        $productHtml = $this->createMockProductHtml('Test Product');
        $this->mockHandler->append(new Response(200, [], $productHtml));

        // Mock review URL pattern testing (3 patterns, first one works)
        $emptyReviewsHtml = '<html><body><div>No reviews found</div></body></html>';
        $this->mockHandler->append(new Response(200, [], $emptyReviewsHtml));

        // Mock empty reviews page response
        $this->mockHandler->append(new Response(200, [], $emptyReviewsHtml));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');
        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_with_cookie_expiration_detection()
    {
        // Mock AlertService to verify cookie expiration alert
        $alertService = Mockery::mock(AlertService::class);
        $alertService->shouldReceive('amazonSessionExpired')
            ->once()
            ->with(
                'Amazon scraping session may have expired - no reviews found',
                Mockery::on(function ($context) {
                    return $context['asin'] === 'B08N5WRWNW' 
                        && $context['service'] === 'direct_scraping'
                        && $context['method'] === 'fetchReviews';
                })
            );

        $this->app->instance(AlertService::class, $alertService);

        // Mock product page response
        $productHtml = $this->createMockProductHtml('Test Product');
        $this->mockHandler->append(new Response(200, [], $productHtml));

        // Mock review URL pattern testing (3 patterns, first one works)
        $emptyReviewsHtml = '<html><body><div>No reviews found</div></body></html>';
        $this->mockHandler->append(new Response(200, [], $emptyReviewsHtml));

        // Mock empty reviews page response
        $this->mockHandler->append(new Response(200, [], $emptyReviewsHtml));

        // Mock cookie expiration detection response
        $signInHtml = '<html><body><div>sign in to amazon</div></body></html>';
        $this->mockHandler->append(new Response(200, [], $signInHtml));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');
        $this->assertEmpty($result);
    }

    public function test_fetch_reviews_handles_multiple_pages()
    {
        // Mock product page response
        $productHtml = $this->createMockProductHtml('Test Product');
        $this->mockHandler->append(new Response(200, [], $productHtml));

        // Mock review URL pattern testing (3 patterns, first one works)
        $reviewsHtml1 = $this->createMockReviewsHtml(['Review 1', 'Review 2']);
        $this->mockHandler->append(new Response(200, [], $reviewsHtml1));

        // Mock first reviews page
        $this->mockHandler->append(new Response(200, [], $reviewsHtml1));

        // Mock second reviews page
        $reviewsHtml2 = $this->createMockReviewsHtml(['Review 3', 'Review 4']);
        $this->mockHandler->append(new Response(200, [], $reviewsHtml2));

        // Mock empty third page (to stop pagination)
        $emptyReviewsHtml = '<html><body><div>No reviews found</div></body></html>';
        $this->mockHandler->append(new Response(200, [], $emptyReviewsHtml));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertCount(4, $result['reviews']);
    }

    public function test_fetch_reviews_handles_network_exception()
    {
        // Mock network exception
        $this->mockHandler->append(new \GuzzleHttp\Exception\ConnectException(
            'Connection timeout',
            new \GuzzleHttp\Psr7\Request('GET', 'test')
        ));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');
        $this->assertEmpty($result);
    }

    public function test_parses_review_data_correctly()
    {
        // Mock product page response
        $productHtml = $this->createMockProductHtml('Test Product');
        $this->mockHandler->append(new Response(200, [], $productHtml));

        // Mock review URL pattern testing (3 patterns, first one works)
        $reviewsHtml = $this->createMockReviewsHtml([
            'This is a great product! I love it.',
            'Not bad, but could be better.'
        ], [5, 3], ['John Doe', 'Jane Smith']);
        $this->mockHandler->append(new Response(200, [], $reviewsHtml));

        // Mock the actual reviews page request
        $this->mockHandler->append(new Response(200, [], $reviewsHtml));

        $result = $this->service->fetchReviews('B08N5WRWNW', 'us');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('reviews', $result);
        $this->assertCount(2, $result['reviews']);

        $review1 = $result['reviews'][0];
        $this->assertEquals(5, $review1['rating']);
        $this->assertEquals('This is a great product! I love it.', $review1['review_text']);
        $this->assertEquals('This is a great product! I love it.', $review1['text']); // Backward compatibility
        $this->assertEquals('John Doe', $review1['author']);
        $this->assertArrayHasKey('id', $review1);
        $this->assertArrayHasKey('review_title', $review1);

        $review2 = $result['reviews'][1];
        $this->assertEquals(3, $review2['rating']);
        $this->assertEquals('Not bad, but could be better.', $review2['review_text']);
        $this->assertEquals('Not bad, but could be better.', $review2['text']); // Backward compatibility
        $this->assertEquals('Jane Smith', $review2['author']);
        $this->assertArrayHasKey('id', $review2);
        $this->assertArrayHasKey('review_title', $review2);
    }

    /**
     * Create mock HTML for Amazon product page.
     */
    private function createMockProductHtml(string $title): string
    {
        return "
        <html>
        <head><title>Amazon Product</title></head>
        <body>
            <div id='productTitle'>{$title}</div>
            <div class='product-details'>
                <span>Product details here</span>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Create mock HTML for Amazon reviews page.
     */
    private function createMockReviewsHtml(array $reviewTexts = null, array $ratings = null, array $authors = null): string
    {
        $reviewTexts = $reviewTexts ?? ['Great product!', 'Good value for money'];
        $ratings = $ratings ?? [5, 4];
        $authors = $authors ?? ['Customer 1', 'Customer 2'];

        $reviewsHtml = '<html><head><title>Amazon Reviews</title></head><body>';
        
        for ($i = 0; $i < count($reviewTexts); $i++) {
            $rating = $ratings[$i] ?? 5;
            $text = $reviewTexts[$i];
            $author = $authors[$i] ?? 'Anonymous';
            
            $reviewsHtml .= "
            <div data-hook='review'>
                <div class='review-rating'>
                    <span class='a-icon-alt'>{$rating}.0 out of 5 stars</span>
                </div>
                <div data-hook='review-body'>
                    <span>{$text}</span>
                </div>
                <div data-hook='review-author'>
                    <span class='a-profile-name'>{$author}</span>
                </div>
            </div>
            ";
        }
        
        $reviewsHtml .= '</body></html>';
        
        return $reviewsHtml;
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('AMAZON_COOKIES');
        
        parent::tearDown();
    }
} 