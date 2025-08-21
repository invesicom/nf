<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AmazonAffiliateLinkTest extends TestCase
{
    use RefreshDatabase;

    #[\PHPUnit\Framework\Attributes\Test]
    public function amazon_urls_include_affiliate_tag_when_configured()
    {
        // Set affiliate tag in config
        config(['app.amazon_affiliate_tag' => 'nullfake-20']);

        // Create test ASIN data
        $asinData = AsinData::factory()->create([
            'asin' => 'B08N5WRWNW',
            'country' => 'us',
            'have_product_data' => true,
            'product_title' => 'Test Product',
        ]);

        // Visit product page with country
        $response = $this->get("/amazon/{$asinData->country}/{$asinData->asin}/{$asinData->slug}");

        $response->assertStatus(200);
        $response->assertSee('https://www.amazon.com/dp/B08N5WRWNW?tag=nullfake-20');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function amazon_urls_work_without_affiliate_tag()
    {
        // No affiliate tag configured
        config(['app.amazon_affiliate_tag' => null]);

        // Create test ASIN data
        $asinData = AsinData::factory()->create([
            'asin' => 'B08N5WRWNW',
            'country' => 'us',
            'have_product_data' => true,
            'product_title' => 'Test Product',
        ]);

        // Visit product page with country
        $response = $this->get("/amazon/{$asinData->country}/{$asinData->asin}/{$asinData->slug}");

        $response->assertStatus(200);
        $response->assertSee('https://www.amazon.com/dp/B08N5WRWNW');
        $response->assertDontSee('?tag=');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function product_not_found_page_includes_affiliate_tag()
    {
        // Set affiliate tag in config
        config(['app.amazon_affiliate_tag' => 'nullfake-20']);

        // Visit non-existent product
        $response = $this->get('/amazon/B08N5NONEX');

        $response->assertStatus(200);
        $response->assertSee('https://www.amazon.com/dp/B08N5NONEX?tag=nullfake-20');
    }
}
