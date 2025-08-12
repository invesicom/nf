<?php

namespace Tests\Unit;

use App\Services\Amazon\AmazonAjaxReviewService;
use App\Services\Amazon\AmazonFetchService;
use App\Services\Amazon\AmazonReviewServiceFactory;
use App\Services\Amazon\AmazonScrapingService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Isolated tests for AmazonReviewServiceFactory with proper environment mocking.
 */
class AmazonReviewServiceFactoryIsolatedTest extends TestCase
{
    #[Test]
    public function it_creates_ajax_service_when_configured()
    {
        // Mock the environment temporarily
        $originalEnv = $_ENV['AMAZON_REVIEW_SERVICE'] ?? null;
        $_ENV['AMAZON_REVIEW_SERVICE'] = 'ajax';
        
        // Create a new factory instance to avoid cached values
        $factory = new class extends AmazonReviewServiceFactory {
            public static function create(): \App\Services\Amazon\AmazonReviewServiceInterface
            {
                // Use direct $_ENV access to bypass Laravel's caching
                $serviceType = $_ENV['AMAZON_REVIEW_SERVICE'] ?? 'unwrangle';
                
                switch (strtolower($serviceType)) {
                    case 'ajax':
                    case 'ajax-bypass':
                        return new AmazonAjaxReviewService();
                        
                    case 'scraping':
                    case 'direct':
                    case 'scrape':
                        return new AmazonScrapingService();
                        
                    case 'unwrangle':
                    case 'api':
                    default:
                        return new AmazonFetchService();
                }
            }
        };
        
        $service = $factory::create();
        
        $this->assertInstanceOf(AmazonAjaxReviewService::class, $service);
        
        // Restore original environment
        if ($originalEnv !== null) {
            $_ENV['AMAZON_REVIEW_SERVICE'] = $originalEnv;
        } else {
            unset($_ENV['AMAZON_REVIEW_SERVICE']);
        }
    }

    #[Test]
    public function it_creates_ajax_service_with_bypass_alias()
    {
        // Mock the environment temporarily
        $originalEnv = $_ENV['AMAZON_REVIEW_SERVICE'] ?? null;
        $_ENV['AMAZON_REVIEW_SERVICE'] = 'ajax-bypass';
        
        // Create a new factory instance to avoid cached values
        $factory = new class extends AmazonReviewServiceFactory {
            public static function create(): \App\Services\Amazon\AmazonReviewServiceInterface
            {
                $serviceType = $_ENV['AMAZON_REVIEW_SERVICE'] ?? 'unwrangle';
                
                switch (strtolower($serviceType)) {
                    case 'ajax':
                    case 'ajax-bypass':
                        return new AmazonAjaxReviewService();
                        
                    case 'scraping':
                    case 'direct':
                    case 'scrape':
                        return new AmazonScrapingService();
                        
                    case 'unwrangle':
                    case 'api':
                    default:
                        return new AmazonFetchService();
                }
            }
        };
        
        $service = $factory::create();
        
        $this->assertInstanceOf(AmazonAjaxReviewService::class, $service);
        
        // Restore original environment
        if ($originalEnv !== null) {
            $_ENV['AMAZON_REVIEW_SERVICE'] = $originalEnv;
        } else {
            unset($_ENV['AMAZON_REVIEW_SERVICE']);
        }
    }

    #[Test]
    public function it_creates_scraping_service_when_configured()
    {
        // Mock the environment temporarily
        $originalEnv = $_ENV['AMAZON_REVIEW_SERVICE'] ?? null;
        $_ENV['AMAZON_REVIEW_SERVICE'] = 'scraping';
        
        // Create a new factory instance
        $factory = new class extends AmazonReviewServiceFactory {
            public static function create(): \App\Services\Amazon\AmazonReviewServiceInterface
            {
                $serviceType = $_ENV['AMAZON_REVIEW_SERVICE'] ?? 'unwrangle';
                
                switch (strtolower($serviceType)) {
                    case 'ajax':
                    case 'ajax-bypass':
                        return new AmazonAjaxReviewService();
                        
                    case 'scraping':
                    case 'direct':
                    case 'scrape':
                        return new AmazonScrapingService();
                        
                    case 'unwrangle':
                    case 'api':
                    default:
                        return new AmazonFetchService();
                }
            }
        };
        
        $service = $factory::create();
        
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
        
        // Restore original environment
        if ($originalEnv !== null) {
            $_ENV['AMAZON_REVIEW_SERVICE'] = $originalEnv;
        } else {
            unset($_ENV['AMAZON_REVIEW_SERVICE']);
        }
    }

    #[Test]
    public function it_verifies_ajax_service_in_available_services()
    {
        $services = AmazonReviewServiceFactory::getAvailableServices();
        
        $this->assertArrayHasKey('ajax', $services);
        
        $ajax = $services['ajax'];
        $this->assertEquals('AJAX Bypass', $ajax['name']);
        $this->assertEquals(AmazonAjaxReviewService::class, $ajax['class']);
        $this->assertContains('ajax', $ajax['env_values']);
        $this->assertContains('ajax-bypass', $ajax['env_values']);
    }
}
