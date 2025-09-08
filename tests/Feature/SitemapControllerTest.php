<?php

namespace Tests\Feature;

use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SitemapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_sitemap_returns_xml_response()
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false);
        $response->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false);
    }

    public function test_main_sitemap_includes_static_pages()
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee('<loc>' . url('/') . '</loc>', false);
        $response->assertSee('<loc>' . url('/privacy') . '</loc>', false);
        $response->assertSee('<priority>1.0</priority>', false); // Homepage priority
        $response->assertSee('<changefreq>weekly</changefreq>', false);
    }

    public function test_main_sitemap_includes_recent_analyzed_products()
    {
        // Create analyzed products
        $product1 = AsinData::factory()->create([
            'status' => 'completed',
            'fake_percentage' => 25.5,
            'have_product_data' => true,
            'asin' => 'B123456789',
            'country' => 'us',
            'product_title' => 'Test Product 1'
        ]);

        $product2 = AsinData::factory()->create([
            'status' => 'completed',
            'fake_percentage' => 15.0,
            'have_product_data' => false,
            'asin' => 'B987654321',
            'country' => 'ca',
            'product_title' => 'Test Product 2'
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertSee($product1->seo_url);
        $response->assertSee($product2->seo_url);
    }

    public function test_main_sitemap_excludes_incomplete_products()
    {
        // Create incomplete products that should not appear
        AsinData::factory()->create([
            'status' => 'pending',
            'fake_percentage' => null,
            'asin' => 'B111111111',
            'country' => 'us'
        ]);

        AsinData::factory()->create([
            'status' => 'completed',
            'fake_percentage' => null, // No analysis completed
            'asin' => 'B222222222',
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertDontSee('B111111111');
        $response->assertDontSee('B222222222');
    }

    public function test_main_sitemap_limits_to_100_products()
    {
        // Create 150 analyzed products
        AsinData::factory()->count(150)->create([
            'status' => 'completed',
            'fake_percentage' => 20.0,
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        
        // Count URL entries (should be 102: 2 static pages + 100 products)
        $content = $response->getContent();
        $urlCount = substr_count($content, '<url>');
        $this->assertEquals(102, $urlCount);
    }

    public function test_product_sitemap_returns_paginated_results()
    {
        // Create 25 analyzed products
        AsinData::factory()->count(25)->create([
            'status' => 'completed',
            'fake_percentage' => 30.0,
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap-products-1.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        
        // Should contain all 25 products
        $content = $response->getContent();
        $urlCount = substr_count($content, '<url>');
        $this->assertEquals(25, $urlCount);
    }

    public function test_product_sitemap_returns_empty_for_invalid_page()
    {
        // Create only 5 products
        AsinData::factory()->count(5)->create([
            'status' => 'completed',
            'fake_percentage' => 30.0,
            'country' => 'us'
        ]);

        // Request page 2 (should be empty)
        $response = $this->get('/sitemap-products-2.xml');

        $response->assertStatus(200);
        $response->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false);
        
        // Should contain no URL entries
        $content = $response->getContent();
        $urlCount = substr_count($content, '<url>');
        $this->assertEquals(0, $urlCount);
    }

    public function test_sitemap_index_with_few_products()
    {
        // Create only 50 products (less than 1000)
        AsinData::factory()->count(50)->create([
            'status' => 'completed',
            'fake_percentage' => 25.0,
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap-index.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false);
        $response->assertSee('<loc>' . url('/sitemap.xml') . '</loc>', false);
        
        // Should only reference main sitemap, no product sitemaps
        $content = $response->getContent();
        $sitemapCount = substr_count($content, '<sitemap>');
        $this->assertEquals(1, $sitemapCount);
    }

    public function test_sitemap_index_with_many_products()
    {
        // Create 2500 products (more than 1000, should create multiple sitemaps)
        AsinData::factory()->count(2500)->create([
            'status' => 'completed',
            'fake_percentage' => 25.0,
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap-index.xml');

        $response->assertStatus(200);
        $response->assertSee('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false);
        $response->assertSee('<loc>' . url('/sitemap.xml') . '</loc>', false);
        $response->assertSee('<loc>' . url('/sitemap-products-1.xml') . '</loc>', false);
        $response->assertSee('<loc>' . url('/sitemap-products-2.xml') . '</loc>', false);
        $response->assertSee('<loc>' . url('/sitemap-products-3.xml') . '</loc>', false);
        
        // Should have main sitemap + 3 product sitemaps (ceil(2500/1000) = 3)
        $content = $response->getContent();
        $sitemapCount = substr_count($content, '<sitemap>');
        $this->assertEquals(4, $sitemapCount);
    }

    public function test_sitemap_caching_works()
    {
        // Clear any existing cache
        Cache::flush();

        // First request should cache the result
        $response1 = $this->get('/sitemap.xml');
        $response1->assertStatus(200);

        // Verify cache was created
        $this->assertTrue(Cache::has('sitemap_main'));

        // Second request should use cached result
        $response2 = $this->get('/sitemap.xml');
        $response2->assertStatus(200);
        
        // Content should be identical
        $this->assertEquals($response1->getContent(), $response2->getContent());
    }

    public function test_sitemap_cache_headers()
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_product_sitemap_cache_headers()
    {
        AsinData::factory()->create([
            'status' => 'completed',
            'fake_percentage' => 25.0,
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap-products-1.xml');

        $response->assertStatus(200);
        $response->assertHeader('Cache-Control', 'max-age=1800, public');
    }

    public function test_sitemap_memory_efficiency_with_large_dataset()
    {
        // Create a large number of products to test memory efficiency
        // This should not cause memory exhaustion like the old implementation
        AsinData::factory()->count(1000)->create([
            'status' => 'completed',
            'fake_percentage' => 25.0,
            'country' => 'us'
        ]);

        // Measure memory before
        $memoryBefore = memory_get_usage();

        $response = $this->get('/sitemap.xml');

        // Measure memory after
        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        $response->assertStatus(200);
        
        // Memory usage should be reasonable (less than 50MB for this test)
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Sitemap generation used too much memory');
    }

    public function test_product_priority_calculation()
    {
        // Create products with different characteristics
        $highPriorityProduct = AsinData::factory()->create([
            'status' => 'completed',
            'fake_percentage' => 5.0, // Low fake percentage (trustworthy)
            'grade' => 'A', // Good grade
            'have_product_data' => true, // Complete data
            'country' => 'us'
        ]);

        $lowPriorityProduct = AsinData::factory()->create([
            'status' => 'completed',
            'fake_percentage' => 80.0, // High fake percentage
            'grade' => 'F', // Bad grade
            'have_product_data' => false, // Incomplete data
            'country' => 'us'
        ]);

        $response = $this->get('/sitemap-products-1.xml');

        $response->assertStatus(200);
        
        $content = $response->getContent();
        
        // Both products should be present
        $this->assertStringContainsString($highPriorityProduct->seo_url, $content);
        $this->assertStringContainsString($lowPriorityProduct->seo_url, $content);
        
        // Should contain priority values
        $this->assertStringContainsString('<priority>', $content);
    }
}
