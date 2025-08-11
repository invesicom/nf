<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonScrapingService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Symfony\Component\DomCrawler\Crawler;

class DebugAmazonScraping extends Command
{
    protected $signature = 'debug:amazon-scraping {asin} {--save-html} {--url-test}';
    
    protected $description = 'Debug Amazon scraping with detailed output and HTML inspection';

    private $scrapingService;

    public function handle(AmazonScrapingService $scrapingService)
    {
        $this->scrapingService = $scrapingService;
        
        $asin = $this->argument('asin');
        $saveHtml = $this->option('save-html');
        $urlTest = $this->option('url-test');
        
        $this->info("Debugging Amazon scraping for ASIN: {$asin}");
        $this->newLine();
        
        try {
            if ($urlTest) {
                $this->testUrls($asin);
            } else {
                $this->debugFullScraping($asin, $saveHtml);
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
        }
    }
    
    private function testUrls($asin)
    {
        $this->info("Testing different Amazon URL patterns...");
        
        $cookieJar = new CookieJar();
        $this->setupCookies($cookieJar);
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
        ];

        $client = new Client([
            'timeout' => 30,
            'http_errors' => false,
            'verify' => false,
            'cookies' => $cookieJar,
            'headers' => $headers,
        ]);
        
        $urlPatterns = [
            "https://www.amazon.com/product-reviews/{$asin}",
            "https://www.amazon.com/dp/product-reviews/{$asin}",
            "https://www.amazon.com/gp/product/{$asin}/reviews",
            "https://www.amazon.com/dp/{$asin}",
            "https://www.amazon.com/gp/customer-reviews/widgets/average-customer-review/popover/ref=dpx_acr_pop_?contextId=dpx&asin={$asin}",
        ];
        
        foreach ($urlPatterns as $url) {
            $this->line("Testing: {$url}");
            
            try {
                $response = $client->get($url);
                $statusCode = $response->getStatusCode();
                $contentLength = strlen($response->getBody()->getContents());
                
                $status = $statusCode === 200 ? 'SUCCESS' : ($statusCode >= 300 && $statusCode < 400 ? 'REDIRECT' : 'FAILED');
                $this->line("  {$status} Status: {$statusCode}, Content Length: {$contentLength} bytes");
                
                if ($statusCode === 200 && $contentLength > 1000) {
                    $this->line("  PROMISING - This URL looks good!");
                }
                
            } catch (\Exception $e) {
                $this->line("  ERROR: " . $e->getMessage());
            }
            
            $this->newLine();
        }
    }
    
    private function debugFullScraping($asin, $saveHtml)
    {
        $service = $this->scrapingService;
        
        $this->info("Step 1: Testing product page scraping...");
        
        // Test product page
        $cookieJar = new CookieJar();
        $this->setupCookies($cookieJar);
        
        $client = new Client([
            'timeout' => 30,
            'http_errors' => false,
            'verify' => false,
            'cookies' => $cookieJar,
        ]);
        
        $productUrl = "https://www.amazon.com/dp/{$asin}";
        $this->line("Fetching: {$productUrl}");
        
        $response = $client->get($productUrl);
        $statusCode = $response->getStatusCode();
        $html = $response->getBody()->getContents();
        
        $this->line("Status: {$statusCode}");
        $this->line("Content Length: " . strlen($html) . " bytes");
        
        if ($saveHtml) {
            file_put_contents("debug_product_{$asin}.html", $html);
            $this->line("Saved HTML to debug_product_{$asin}.html");
        }
        
        $this->newLine();
        $this->info("Step 2: Testing review page patterns...");
        
        $reviewUrls = [
            "https://www.amazon.com/product-reviews/{$asin}",
            "https://www.amazon.com/dp/product-reviews/{$asin}",
            "https://www.amazon.com/gp/product/{$asin}/reviews",
        ];
        
        foreach ($reviewUrls as $url) {
            $this->line("Testing: {$url}");
            
            try {
                $response = $client->get($url);
                $statusCode = $response->getStatusCode();
                $html = $response->getBody()->getContents();
                
                $this->line("  Status: {$statusCode}");
                $this->line("  Content Length: " . strlen($html) . " bytes");
                
                if ($statusCode === 200) {
                    // Try to find review indicators in the HTML
                    $crawler = new Crawler($html);
                    
                    $reviewSelectors = [
                        '[data-hook="review"]',
                        '.review',
                        '.cr-original-review-item',
                        '[data-hook="review-body"]'
                    ];
                    
                    foreach ($reviewSelectors as $selector) {
                        try {
                            $nodes = $crawler->filter($selector);
                            if ($nodes->count() > 0) {
                                $this->line("  SUCCESS - Found {$nodes->count()} elements with selector: {$selector}");
                            }
                        } catch (\Exception $e) {
                            // Continue
                        }
                    }
                    
                    // Look for common Amazon review patterns in the raw HTML
                    $patterns = [
                        '/data-hook="review"/' => 'data-hook="review"',
                        '/"review-rating"/' => 'review-rating',
                        '/"review-title"/' => 'review-title',
                        '/"review-body"/' => 'review-body',
                        '/star rating/' => 'star rating text',
                        '/out of 5 stars/' => 'rating text pattern',
                    ];
                    
                    foreach ($patterns as $pattern => $description) {
                        if (preg_match($pattern, $html)) {
            $this->line("  FOUND - Pattern: {$description}");
                        }
                    }
                    
                    if ($saveHtml) {
                        $filename = "debug_reviews_{$asin}_" . parse_url($url, PHP_URL_PATH) . ".html";
                        $filename = str_replace(['/', '-'], '_', $filename);
                        file_put_contents($filename, $html);
                        $this->line("  Saved HTML to {$filename}");
                    }
                }
                
            } catch (\Exception $e) {
                $this->line("  ERROR: " . $e->getMessage());
            }
            
            $this->newLine();
        }
        
        $this->info("Step 3: Testing full service method...");
        $reviewsData = $service->fetchReviews($asin);
        
        $this->line("Service returned:");
        $this->line("  Description: " . substr($reviewsData['description'] ?? 'N/A', 0, 100) . "...");
        $this->line("  Total Reviews: " . ($reviewsData['total_reviews'] ?? 'N/A'));
        $this->line("  Reviews Array Count: " . count($reviewsData['reviews'] ?? []));
        
        if (!empty($reviewsData['reviews'])) {
            $this->line("  Sample Review Keys: " . implode(', ', array_keys($reviewsData['reviews'][0])));
        }
    }
    
    private function setupCookies($cookieJar)
    {
        // Use the new multi-session cookie manager
        $cookieSessionManager = new \App\Services\Amazon\CookieSessionManager();
        
        $this->info("Available cookie sessions: " . $cookieSessionManager->getSessionCount());
        
        // Show session information
        $sessionInfo = $cookieSessionManager->getSessionInfo();
        foreach ($sessionInfo as $info) {
            $healthStatus = $info['is_healthy'] ? 'Healthy' : 'Unhealthy';
            $currentMark = $info['is_current'] ? ' (current)' : '';
            $this->line("  {$info['name']}: {$healthStatus}, {$info['cookie_count']} cookies{$currentMark}");
        }
        
        $session = $cookieSessionManager->getNextCookieSession();
        
        if (!$session) {
            $this->warn("No Amazon cookie sessions available - trying legacy AMAZON_COOKIES");
            $this->setupLegacyCookies($cookieJar);
            return;
        }
        
        // Replace cookie jar with the one from the session manager
        $sessionCookieJar = $cookieSessionManager->createCookieJar($session);
        
        // Copy cookies from session jar to our jar
        foreach ($sessionCookieJar as $cookie) {
            $cookieJar->setCookie($cookie);
        }
        
        $this->info("Using session: {$session['name']} ({$session['env_var']})");
    }
    
    private function setupLegacyCookies($cookieJar)
    {
        $cookieString = env('AMAZON_COOKIES', '');
        
        if (empty($cookieString)) {
            $this->warn("No AMAZON_COOKIES configured in environment");
            return;
        }

        $cookies = explode(';', $cookieString);
        
        foreach ($cookies as $cookie) {
            $cookie = trim($cookie);
            if (empty($cookie)) continue;
            
            $parts = explode('=', $cookie, 2);
            if (count($parts) !== 2) continue;
            
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            
            $cookieJar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => '.amazon.com',
                'Path' => '/',
                'Secure' => true,
                'HttpOnly' => true,
            ]));
        }
        
        $this->line("Loaded " . count($cookies) . " cookies from legacy configuration");
    }
} 