<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ContentSecurityPolicy
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply CSP to HTML responses
        if ($response->headers->get('Content-Type') && 
            str_contains($response->headers->get('Content-Type'), 'text/html')) {
            
            // Build CSP policy
            $cspPolicy = $this->buildCspPolicy($request);
            
            // Set CSP header
            $response->headers->set('Content-Security-Policy', $cspPolicy);
            
            // Also set X-Content-Type-Options for additional security
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            
            // Set X-Frame-Options to prevent clickjacking
            $response->headers->set('X-Frame-Options', 'DENY');
            
            // Set X-XSS-Protection (legacy browsers)
            $response->headers->set('X-XSS-Protection', '1; mode=block');
        }

        return $response;
    }

    /**
     * Build the Content Security Policy string.
     */
    private function buildCspPolicy(Request $request): string
    {
        $policies = [
            // Default source - only allow same origin
            "default-src 'self'",
            
            // Scripts - allow self, inline scripts, CDNs, and CAPTCHA providers
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://www.google.com https://js.hcaptcha.com",
            
            // Styles - allow self, inline styles, and CDNs for Tailwind/fonts
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
            
            // Images - allow self, data URIs, and Amazon/external image sources
            "img-src 'self' data: https: http:",
            
            // Fonts - allow self and Google Fonts
            "font-src 'self' https://fonts.gstatic.com",
            
            // Connect - allow self for AJAX requests and CAPTCHA verification
            "connect-src 'self' https://www.google.com https://hcaptcha.com",
            
            // Media - allow self
            "media-src 'self'",
            
            // Objects - disallow all
            "object-src 'none'",
            
            // Base URI - only allow self
            "base-uri 'self'",
            
            // Form actions - only allow self
            "form-action 'self'",
            
            // Frame sources - allow CAPTCHA frames
            "frame-src https://www.google.com https://hcaptcha.com",
            
            // Frame ancestors - deny all (prevent embedding)
            "frame-ancestors 'none'",
            
            // Upgrade insecure requests in production
            app()->environment('production') ? "upgrade-insecure-requests" : "",
        ];

        // Filter out empty policies and join with semicolons
        return implode('; ', array_filter($policies));
    }
}
