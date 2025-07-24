<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test environment with Bright Data proxy
        putenv('BRIGHTDATA_USERNAME=brd-customer-test-zone-residential');
        putenv('BRIGHTDATA_PASSWORD=test-password');
        putenv('BRIGHTDATA_ENDPOINT=brd.superproxy.io:33335');
    }

    public function test_proxy_status_command_shows_configuration()
    {
        $this->artisan('proxy:manage status')
            ->expectsOutput('Proxy System Status')
            ->expectsOutput('Third-party Providers: 1')
            ->expectsOutput('Custom Proxies: 0')
            ->expectsOutput('Bright Data Username: [CONFIGURED]')
            ->assertExitCode(0);
    }

    public function test_proxy_status_command_shows_production_configuration()
    {
        // In production, Bright Data is configured, so test reflects that
        $this->artisan('proxy:manage status')
            ->expectsOutput('Proxy System Status')
            ->expectsOutput('Third-party Providers: 1')
            ->expectsOutput('Bright Data Username: [CONFIGURED]')
            ->assertExitCode(0);
    }

    public function test_proxy_rotate_command_works()
    {
        $this->artisan('proxy:manage rotate')
            ->expectsOutput('Rotating proxy configuration...')
            ->expectsOutput('SUCCESS - Proxy session rotated')
            ->assertExitCode(0);
    }

    public function test_test_amazon_scraping_command_basic_mode()
    {
        // Mock the Amazon scraping service to avoid real network calls
        $this->mock(\App\Services\Amazon\AmazonScrapingService::class, function ($mock) {
            $mock->shouldReceive('fetchReviews')->andReturn([
                'description' => 'Test product description',
                'total_reviews' => 100,
                'reviews' => [
                    ['id' => 1, 'rating' => 5, 'review_title' => 'Great!', 'review_text' => 'Great product', 'author' => 'John']
                ]
            ]);
        });

        $this->artisan('test:amazon-scraping B0CBC67ZXC')
            ->expectsOutput('Testing Amazon scraping for ASIN: B0CBC67ZXC')
            ->assertExitCode(0);
    }

    public function test_test_amazon_scraping_command_reviews_only()
    {
        // Mock the Amazon scraping service to avoid real network calls
        $this->mock(\App\Services\Amazon\AmazonScrapingService::class, function ($mock) {
            $mock->shouldReceive('fetchReviews')->andReturn([
                'reviews' => [
                    ['id' => 1, 'rating' => 5, 'review_title' => 'Great!', 'review_text' => 'Great product', 'author' => 'John'],
                    ['id' => 2, 'rating' => 4, 'review_title' => 'Good', 'review_text' => 'Good product', 'author' => 'Jane']
                ]
            ]);
        });

        $this->artisan('test:amazon-scraping B0CBC67ZXC --reviews-only')
            ->expectsOutput('Testing Amazon scraping for ASIN: B0CBC67ZXC')
            ->assertExitCode(0);
    }

    public function test_test_amazon_scraping_command_invalid_asin()
    {
        // Mock the Amazon scraping service to return empty for invalid ASIN
        $this->mock(\App\Services\Amazon\AmazonScrapingService::class, function ($mock) {
            $mock->shouldReceive('fetchReviews')->andReturn([]);
        });

        $this->artisan('test:amazon-scraping INVALID123')
            ->expectsOutput('Testing Amazon scraping for ASIN: INVALID123')
            ->assertExitCode(0);
    }

    public function test_debug_amazon_scraping_command_basic()
    {
        // Mock the Amazon scraping service for debug command
        $this->mock(\App\Services\Amazon\AmazonScrapingService::class, function ($mock) {
            $mock->shouldReceive('fetchReviews')->andReturn([
                'description' => 'Debug test product',
                'reviews' => []
            ]);
            // Allow any other method calls that might be needed for debugging
            $mock->shouldIgnoreMissing();
        });

        $this->artisan('debug:amazon-scraping B0CBC67ZXC')
            ->expectsOutput('Debugging Amazon scraping for ASIN: B0CBC67ZXC')
            ->assertExitCode(0);
    }

    public function test_debug_amazon_scraping_command_url_test()
    {
        // This test only tests URL patterns, so it doesn't need the scraping service
        // But we'll mock it anyway in case it's called
        $this->mock(\App\Services\Amazon\AmazonScrapingService::class, function ($mock) {
            $mock->shouldIgnoreMissing();
        });

        $this->artisan('debug:amazon-scraping B0CBC67ZXC --url-test')
            ->expectsOutput('Debugging Amazon scraping for ASIN: B0CBC67ZXC')
            ->assertExitCode(0);
    }

    public function test_proxy_commands_handle_missing_asin()
    {
        // Commands require ASIN argument, test that Laravel throws proper exception
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->artisan('test:amazon-scraping');
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('BRIGHTDATA_USERNAME=');
        putenv('BRIGHTDATA_PASSWORD=');
        putenv('BRIGHTDATA_ENDPOINT=');
        
        parent::tearDown();
    }
} 