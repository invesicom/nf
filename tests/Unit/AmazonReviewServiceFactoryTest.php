<?php

namespace Tests\Unit;

use App\Services\Amazon\AmazonFetchService;
use App\Services\Amazon\AmazonReviewServiceFactory;
use App\Services\Amazon\AmazonScrapingService;
use Tests\TestCase;

class AmazonReviewServiceFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear any existing environment variables
        putenv('AMAZON_REVIEW_SERVICE');
    }

    public function test_creates_unwrangle_service_by_default()
    {
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonFetchService::class, $service);
    }

    public function test_creates_unwrangle_service_when_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=unwrangle');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonFetchService::class, $service);
    }

    public function test_creates_unwrangle_service_with_api_alias()
    {
        putenv('AMAZON_REVIEW_SERVICE=api');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonFetchService::class, $service);
    }

    public function test_creates_scraping_service_when_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
    }

    public function test_creates_scraping_service_with_direct_alias()
    {
        putenv('AMAZON_REVIEW_SERVICE=direct');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
    }

    public function test_creates_scraping_service_with_scrape_alias()
    {
        putenv('AMAZON_REVIEW_SERVICE=scrape');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
    }

    public function test_get_current_service_type_returns_default()
    {
        $serviceType = AmazonReviewServiceFactory::getCurrentServiceType();
        
        $this->assertEquals('unwrangle', $serviceType);
    }

    public function test_get_current_service_type_returns_configured_value()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $serviceType = AmazonReviewServiceFactory::getCurrentServiceType();
        
        $this->assertEquals('scraping', $serviceType);
    }

    public function test_is_scraping_enabled_returns_false_by_default()
    {
        $isEnabled = AmazonReviewServiceFactory::isScrapingEnabled();
        
        $this->assertFalse($isEnabled);
    }

    public function test_is_scraping_enabled_returns_true_when_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $isEnabled = AmazonReviewServiceFactory::isScrapingEnabled();
        
        $this->assertTrue($isEnabled);
    }

    public function test_is_scraping_enabled_returns_true_for_aliases()
    {
        putenv('AMAZON_REVIEW_SERVICE=direct');
        $this->assertTrue(AmazonReviewServiceFactory::isScrapingEnabled());
        
        putenv('AMAZON_REVIEW_SERVICE=scrape');
        $this->assertTrue(AmazonReviewServiceFactory::isScrapingEnabled());
    }

    public function test_is_unwrangle_enabled_returns_true_by_default()
    {
        $isEnabled = AmazonReviewServiceFactory::isUnwrangleEnabled();
        
        $this->assertTrue($isEnabled);
    }

    public function test_is_unwrangle_enabled_returns_true_when_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=unwrangle');
        
        $isEnabled = AmazonReviewServiceFactory::isUnwrangleEnabled();
        
        $this->assertTrue($isEnabled);
    }

    public function test_is_unwrangle_enabled_returns_false_when_scraping_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $isEnabled = AmazonReviewServiceFactory::isUnwrangleEnabled();
        
        $this->assertFalse($isEnabled);
    }

    public function test_is_unwrangle_enabled_returns_true_for_api_alias()
    {
        putenv('AMAZON_REVIEW_SERVICE=api');
        
        $isEnabled = AmazonReviewServiceFactory::isUnwrangleEnabled();
        
        $this->assertTrue($isEnabled);
    }

    public function test_get_available_services_returns_correct_structure()
    {
        $services = AmazonReviewServiceFactory::getAvailableServices();
        
        $this->assertIsArray($services);
        $this->assertArrayHasKey('unwrangle', $services);
        $this->assertArrayHasKey('scraping', $services);
        
        // Check unwrangle service structure
        $unwrangle = $services['unwrangle'];
        $this->assertArrayHasKey('name', $unwrangle);
        $this->assertArrayHasKey('description', $unwrangle);
        $this->assertArrayHasKey('class', $unwrangle);
        $this->assertArrayHasKey('env_values', $unwrangle);
        $this->assertEquals('Unwrangle API', $unwrangle['name']);
        $this->assertEquals(AmazonFetchService::class, $unwrangle['class']);
        $this->assertContains('unwrangle', $unwrangle['env_values']);
        $this->assertContains('api', $unwrangle['env_values']);
        
        // Check scraping service structure
        $scraping = $services['scraping'];
        $this->assertArrayHasKey('name', $scraping);
        $this->assertArrayHasKey('description', $scraping);
        $this->assertArrayHasKey('class', $scraping);
        $this->assertArrayHasKey('env_values', $scraping);
        $this->assertEquals('Direct Scraping', $scraping['name']);
        $this->assertEquals(AmazonScrapingService::class, $scraping['class']);
        $this->assertContains('scraping', $scraping['env_values']);
        $this->assertContains('direct', $scraping['env_values']);
        $this->assertContains('scrape', $scraping['env_values']);
    }

    public function test_handles_case_insensitive_service_types()
    {
        putenv('AMAZON_REVIEW_SERVICE=SCRAPING');
        $service = AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
        
        putenv('AMAZON_REVIEW_SERVICE=UNWRANGLE');
        $service = AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(AmazonFetchService::class, $service);
        
        putenv('AMAZON_REVIEW_SERVICE=Direct');
        $service = AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
    }

    public function test_unknown_service_type_defaults_to_unwrangle()
    {
        putenv('AMAZON_REVIEW_SERVICE=unknown_service');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonFetchService::class, $service);
    }

    protected function tearDown(): void
    {
        // Clean up environment variables
        putenv('AMAZON_REVIEW_SERVICE');
        
        parent::tearDown();
    }
} 