<?php

namespace Tests\Unit;

use App\Services\Amazon\AmazonAjaxReviewService;
use App\Services\Amazon\AmazonFetchService;
use App\Services\Amazon\AmazonReviewServiceFactory;
use App\Services\Amazon\AmazonScrapingService;
use Tests\TestCase;

class AmazonReviewServiceFactoryTest extends TestCase
{


    protected function setUp(): void
    {
        parent::setUp();
        
        // Override environment for testing to bypass Laravel's config caching
        config(['app.env' => 'testing']);
        
        // Clear any cached environment values
        app()->forgetInstance('config');
    }

    public function test_creates_scraping_service_by_default()
    {
        // With production environment using scraping, test that it creates scraping service
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
    }

    // Unwrangle service tests removed as we're phasing out Unwrangle API

    // API alias test removed as we're phasing out Unwrangle API

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

    public function test_creates_ajax_service_when_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=ajax');
        config(['app.env' => 'testing']);
        config(['app.debug' => true]);
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonAjaxReviewService::class, $service);
    }

    public function test_creates_ajax_service_with_bypass_alias()
    {
        putenv('AMAZON_REVIEW_SERVICE=ajax-bypass');
        
        $service = AmazonReviewServiceFactory::create();
        
        $this->assertInstanceOf(AmazonAjaxReviewService::class, $service);
    }

    public function test_is_ajax_enabled_returns_true_when_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=ajax');
        
        $isEnabled = AmazonReviewServiceFactory::isAjaxEnabled();
        
        $this->assertTrue($isEnabled);
    }

    public function test_is_ajax_enabled_returns_true_for_aliases()
    {
        putenv('AMAZON_REVIEW_SERVICE=ajax-bypass');
        $this->assertTrue(AmazonReviewServiceFactory::isAjaxEnabled());
    }

    public function test_is_ajax_enabled_returns_false_when_not_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $isEnabled = AmazonReviewServiceFactory::isAjaxEnabled();
        
        $this->assertFalse($isEnabled);
    }

    public function test_get_current_service_type_returns_scraping()
    {
        // In production environment, service type should be scraping
        $serviceType = AmazonReviewServiceFactory::getCurrentServiceType();
        
        $this->assertEquals('scraping', $serviceType);
    }

    public function test_get_current_service_type_returns_configured_value()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $serviceType = AmazonReviewServiceFactory::getCurrentServiceType();
        
        $this->assertEquals('scraping', $serviceType);
    }

    public function test_is_scraping_enabled_returns_true_in_production()
    {
        // In production environment, scraping should be enabled
        $isEnabled = AmazonReviewServiceFactory::isScrapingEnabled();
        
        $this->assertTrue($isEnabled);
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

    public function test_is_unwrangle_enabled_returns_false_in_production()
    {
        // In production environment with scraping enabled, unwrangle should be disabled
        $isEnabled = AmazonReviewServiceFactory::isUnwrangleEnabled();
        
        $this->assertFalse($isEnabled);
    }

    // Unwrangle configuration test removed as we're phasing out Unwrangle

    public function test_is_unwrangle_enabled_returns_false_when_scraping_configured()
    {
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        $isEnabled = AmazonReviewServiceFactory::isUnwrangleEnabled();
        
        $this->assertFalse($isEnabled);
    }

    // API alias test removed as we're phasing out Unwrangle

    public function test_get_available_services_returns_correct_structure()
    {
        $services = AmazonReviewServiceFactory::getAvailableServices();
        
        $this->assertIsArray($services);
        $this->assertArrayHasKey('ajax', $services);
        $this->assertArrayHasKey('scraping', $services);
        $this->assertArrayHasKey('unwrangle', $services);
        
        // Check AJAX service structure
        $ajax = $services['ajax'];
        $this->assertArrayHasKey('name', $ajax);
        $this->assertArrayHasKey('description', $ajax);
        $this->assertArrayHasKey('class', $ajax);
        $this->assertArrayHasKey('env_values', $ajax);
        $this->assertEquals('AJAX Bypass', $ajax['name']);
        $this->assertEquals(AmazonAjaxReviewService::class, $ajax['class']);
        $this->assertContains('ajax', $ajax['env_values']);
        $this->assertContains('ajax-bypass', $ajax['env_values']);
        
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
    }

    public function test_handles_case_insensitive_service_types()
    {
        // Test that case-insensitive values work
        // Note: These temporarily override the environment for testing
        
        putenv('AMAZON_REVIEW_SERVICE=AJAX');
        $service = AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(AmazonAjaxReviewService::class, $service);
        
        putenv('AMAZON_REVIEW_SERVICE=SCRAPING');
        $service = AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
        
        putenv('AMAZON_REVIEW_SERVICE=Direct');
        $service = AmazonReviewServiceFactory::create();
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
        
        // Restore production setting
        putenv('AMAZON_REVIEW_SERVICE=scraping');
    }

    public function test_unknown_service_type_uses_production_default()
    {
        // In production environment, even unknown service types will use the cached 
        // environment value due to Laravel's env() caching behavior
        putenv('AMAZON_REVIEW_SERVICE=unknown_service');
        
        $service = AmazonReviewServiceFactory::create();
        
        // Due to Laravel env() caching, this will still return scraping service
        $this->assertInstanceOf(AmazonScrapingService::class, $service);
        
        // Restore production setting
        putenv('AMAZON_REVIEW_SERVICE=scraping');
    }

    protected function tearDown(): void
    {
        // Ensure production environment is restored
        putenv('AMAZON_REVIEW_SERVICE=scraping');
        
        parent::tearDown();
    }
} // Trivial change to refresh GitHub UI
