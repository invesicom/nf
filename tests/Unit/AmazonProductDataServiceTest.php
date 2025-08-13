<?php

namespace Tests\Unit;

use App\Services\Amazon\AmazonProductDataService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AmazonProductDataServiceTest extends TestCase
{
    private AmazonProductDataService $service;
    private MockHandler $mockHandler;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache for clean tests
        Cache::flush();
        
        // Set up mock environment
        config(['app.env' => 'testing']);
        
        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);
        
        // Create service
        $this->service = new AmazonProductDataService();
        
        // Use reflection to inject mock client
        $reflection = new \ReflectionClass($this->service);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->service, new Client(['handler' => $handlerStack]));
    }

    #[Test]
    public function it_returns_mock_data_in_testing_environment_without_cookies()
    {
        // No cookies configured
        putenv('AMAZON_COOKIES_1');
        
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('Test Product B08B39N5CC', $result['title']);
        $this->assertEquals('Mock product description for testing', $result['description']);
        $this->assertStringContainsString('B08B39N5CC', $result['image_url']);
    }

    #[Test]
    public function it_extracts_title_from_meta_tags()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        
        // Temporarily set environment to local to bypass testing mock data
        config(['app.env' => 'local']);
        
        $html = $this->createMockHtmlWithMetaTags(
            'Amazing Bread Banneton Proofing Basket',
            'Perfect for sourdough bread making',
            'https://m.media-amazon.com/images/I/test-image.jpg'
        );
        
        $this->mockHandler->append(new Response(200, [], $html));
        
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('Amazing Bread Banneton Proofing Basket', $result['title']);
        $this->assertEquals('Perfect for sourdough bread making', $result['description']);
        $this->assertEquals('https://m.media-amazon.com/images/I/test-image.jpg', $result['image_url']);
    }

    #[Test]
    public function it_extracts_title_from_og_meta_tags()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        config(['app.env' => 'local']);
        
        $html = $this->createMockHtmlWithOgTags(
            'Sourdough Banneton Basket Set of 2',
            'Professional quality proofing baskets',
            'https://m.media-amazon.com/images/I/og-image.jpg'
        );
        
        $this->mockHandler->append(new Response(200, [], $html));
        
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('Sourdough Banneton Basket Set of 2', $result['title']);
        $this->assertEquals('Professional quality proofing baskets', $result['description']);
        $this->assertEquals('https://m.media-amazon.com/images/I/og-image.jpg', $result['image_url']);
    }

    #[Test]
    public function it_extracts_title_from_twitter_meta_tags()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        config(['app.env' => 'local']);
        
        $html = $this->createMockHtmlWithTwitterTags(
            'Twitter Title: Proofing Basket',
            'Twitter description for bread making',
            'https://m.media-amazon.com/images/I/twitter-image.jpg'
        );
        
        $this->mockHandler->append(new Response(200, [], $html));
        
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('Twitter Title: Proofing Basket', $result['title']);
        $this->assertEquals('Twitter description for bread making', $result['description']);
        $this->assertEquals('https://m.media-amazon.com/images/I/twitter-image.jpg', $result['image_url']);
    }

    #[Test]
    public function it_cleans_amazon_title_prefixes()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        config(['app.env' => 'local']);
        
        $html = $this->createMockHtmlWithMetaTags(
            'Amazon.com: Bread Banneton Proofing Basket - Kitchen Tools',
            'Great for bread making',
            'https://m.media-amazon.com/images/I/test-image.jpg'
        );
        
        $this->mockHandler->append(new Response(200, [], $html));
        
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('Bread Banneton Proofing Basket', $result['title']);
    }

    #[Test]
    public function it_falls_back_to_dom_selectors_when_meta_tags_empty()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        config(['app.env' => 'local']);
        
        $html = $this->createMockHtmlWithDomElements(
            'DOM Title from ProductTitle',
            'DOM description from feature bullets',
            'https://m.media-amazon.com/images/I/dom-image.jpg'
        );
        
        $this->mockHandler->append(new Response(200, [], $html));
        
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('DOM Title from ProductTitle', $result['title']);
        $this->assertEquals('DOM description from feature bullets', $result['description']);
        $this->assertEquals('https://m.media-amazon.com/images/I/dom-image.jpg', $result['image_url']);
    }

    #[Test]
    public function it_uses_cache_when_available()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        config(['app.env' => 'local']);
        
        // Set cache data
        $cacheKey = 'product_data_B08B39N5CC_us';
        $cachedData = [
            'title' => 'Cached Product Title',
            'description' => 'Cached description',
            'image_url' => 'https://cached-image.jpg'
        ];
        Cache::put($cacheKey, $cachedData, 3600);
        
        // No HTTP mock needed - should use cache
        $result = $this->service->scrapeProductData('B08B39N5CC', 'us');
        
        $this->assertIsArray($result);
        $this->assertEquals('Cached Product Title', $result['title']);
        $this->assertEquals('Cached description', $result['description']);
        $this->assertEquals('https://cached-image.jpg', $result['image_url']);
    }

    #[Test]
    public function it_handles_http_errors_gracefully()
    {
        putenv('AMAZON_COOKIES_1=test-cookie=test-value');
        config(['app.env' => 'local']);
        
        $this->mockHandler->append(new Response(404, [], 'Not Found'));
        
        $result = $this->service->scrapeProductData('B08INVALID', 'us');
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    private function createMockHtmlWithMetaTags(string $title, string $description, string $imageUrl): string
    {
        return "
        <html>
        <head>
            <meta name=\"title\" content=\"{$title}\">
            <meta name=\"description\" content=\"{$description}\">
            <title>Amazon.com</title>
        </head>
        <body>
            <img id=\"landingImage\" src=\"{$imageUrl}\" alt=\"Product Image\">
        </body>
        </html>
        ";
    }

    private function createMockHtmlWithOgTags(string $title, string $description, string $imageUrl): string
    {
        return "
        <html>
        <head>
            <meta property=\"og:title\" content=\"{$title}\">
            <meta property=\"og:description\" content=\"{$description}\">
            <meta property=\"og:image\" content=\"{$imageUrl}\">
            <title>Amazon.com</title>
        </head>
        <body>
        </body>
        </html>
        ";
    }

    private function createMockHtmlWithTwitterTags(string $title, string $description, string $imageUrl): string
    {
        return "
        <html>
        <head>
            <meta name=\"twitter:title\" content=\"{$title}\">
            <meta name=\"twitter:description\" content=\"{$description}\">
            <meta name=\"twitter:image\" content=\"{$imageUrl}\">
            <title>Amazon.com</title>
        </head>
        <body>
        </body>
        </html>
        ";
    }

    private function createMockHtmlWithDomElements(string $title, string $description, string $imageUrl): string
    {
        return "
        <html>
        <head>
            <title>Amazon.com</title>
        </head>
        <body>
            <h1 id=\"productTitle\">{$title}</h1>
            <div id=\"feature-bullets\">
                <ul>
                    <li>{$description}</li>
                </ul>
            </div>
            <img id=\"landingImage\" src=\"{$imageUrl}\" alt=\"Product Image\">
        </body>
        </html>
        ";
    }

    protected function tearDown(): void
    {
        putenv('AMAZON_COOKIES_1');
        config(['app.env' => 'testing']); // Reset to testing
        Cache::flush();
        parent::tearDown();
    }
}
