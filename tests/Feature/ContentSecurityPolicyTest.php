<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    #[Test]
    public function test_csp_headers_are_set_on_web_pages()
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        // Check that CSP header is set
        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        
        // Check specific CSP directives
        $cspHeader = $response->headers->get('Content-Security-Policy');
        
        $this->assertStringContainsString("default-src 'self'", $cspHeader);
        $this->assertStringContainsString("script-src 'self'", $cspHeader);
        $this->assertStringContainsString("style-src 'self'", $cspHeader);
        $this->assertStringContainsString("object-src 'none'", $cspHeader);
        $this->assertStringContainsString("frame-ancestors 'none'", $cspHeader);
    }

    #[Test]
    public function test_additional_security_headers_are_set()
    {
        $response = $this->get('/');

        $response->assertStatus(200);

        // Check additional security headers
        $this->assertEquals('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertEquals('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertEquals('1; mode=block', $response->headers->get('X-XSS-Protection'));
    }

    #[Test]
    public function test_csp_headers_not_set_on_api_responses()
    {
        // Test that CSP headers are not set on JSON API responses
        $response = $this->postJson('/api/expand-url', [
            'url' => 'https://amazon.com/dp/B0CGM1RSZH'
        ]);

        // API responses shouldn't have CSP headers since they're not HTML
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    #[Test]
    public function test_csp_allows_required_external_resources()
    {
        $response = $this->get('/');
        
        $cspHeader = $response->headers->get('Content-Security-Policy');
        
        // Check that required external resources are allowed
        $this->assertStringContainsString('https://fonts.googleapis.com', $cspHeader);
        $this->assertStringContainsString('https://fonts.gstatic.com', $cspHeader);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $cspHeader);
        
        // Check that images from external sources are allowed
        $this->assertStringContainsString('img-src', $cspHeader);
        $this->assertStringContainsString('https:', $cspHeader);
    }

    #[Test]
    public function test_production_csp_includes_upgrade_insecure_requests()
    {
        // Temporarily set environment to production
        $originalEnv = app()->environment();
        app()->instance('env', 'production');

        $response = $this->get('/');
        
        $cspHeader = $response->headers->get('Content-Security-Policy');
        
        // In production, should include upgrade-insecure-requests
        $this->assertStringContainsString('upgrade-insecure-requests', $cspHeader);

        // Restore original environment
        app()->instance('env', $originalEnv);
    }
}
