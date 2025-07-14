<?php

namespace App\Services\Amazon;

use App\Models\AsinData;
use App\Services\AlertService;
use App\Services\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for directly scraping Amazon product reviews.
 * 
 * This service scrapes Amazon reviews directly using the same cookie session
 * that Unwrangle uses, providing a cost-effective alternative to the API.
 */
class AmazonScrapingService implements AmazonReviewServiceInterface
{
    private Client $httpClient;
    private CookieJar $cookieJar;
    private array $headers;
    private ProxyManager $proxyManager;
    private ?array $currentProxyConfig = null;

    /**
     * Initialize the service with HTTP client configuration.
     */
    public function __construct()
    {
        $this->proxyManager = new ProxyManager();
        $this->cookieJar = new CookieJar();
        $this->setupCookies();
        
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
            'DNT' => '1',
            'Sec-GPC' => '1',
        ];

        $this->initializeHttpClient();
    }
    
    /**
     * Initialize HTTP client with proxy configuration.
     */
    private function initializeHttpClient(): void
    {
        $clientConfig = [
            'timeout' => 30,
            'connect_timeout' => 15,
            'http_errors' => false,
            'verify' => false,
            'cookies' => $this->cookieJar,
            'headers' => $this->getBandwidthOptimizedHeaders(),
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => true,
                'track_redirects' => true,
            ],
        ];
        
        // Add proxy configuration if available
        $this->currentProxyConfig = $this->proxyManager->getProxyConfig();
        if ($this->currentProxyConfig) {
            $clientConfig['proxy'] = $this->currentProxyConfig['proxy'];
            $clientConfig['timeout'] = $this->currentProxyConfig['timeout'];
            
            // Add specific proxy settings for Amazon with bandwidth optimization
            $clientConfig['curl'] = [
                CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_ENCODING => 'gzip, deflate', // Force compression
                CURLOPT_MAXFILESIZE => 2 * 1024 * 1024, // 2MB limit per request
                CURLOPT_BUFFERSIZE => 16384, // 16KB buffer for faster processing
            ];
            
            LoggingService::log('Using proxy for Amazon scraping with bandwidth optimization', [
                'type' => $this->currentProxyConfig['type'],
                'provider' => $this->currentProxyConfig['provider'] ?? 'custom',
                'country' => $this->currentProxyConfig['country'],
                'session_id' => $this->currentProxyConfig['session_id'] ?? 'none',
                'max_file_size' => '2MB',
                'compression' => 'gzip, deflate'
            ]);
        } else {
            LoggingService::log('No proxy configured - using direct connection');
        }
        
        $this->httpClient = new Client($clientConfig);
    }

    /**
     * Get bandwidth-optimized headers that block unnecessary content.
     */
    private function getBandwidthOptimizedHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', // Reduced priorities for non-HTML
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br', // Force compression
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
            'DNT' => '1',
            'Sec-GPC' => '1',
            // Bandwidth optimization headers
            'Save-Data' => '1', // Request reduced data usage
            'Viewport-Width' => '1024', // Optimize for smaller viewport
            'DPR' => '1', // Device pixel ratio = 1 (no high-DPI images)
        ];
    }

    /**
     * Check if a URL should be blocked to save bandwidth.
     */
    private function shouldBlockUrl(string $url): bool
    {
        // Block common resource-heavy URLs that we don't need for review scraping
        $blockedPatterns = [
            // Images and media
            '/\.(jpg|jpeg|png|gif|webp|svg|ico|bmp)(\?.*)?$/i',
            '/\.(mp4|mp3|avi|mov|wmv|flv|webm)(\?.*)?$/i',
            
            // CSS and styling (we don't need visual styling)
            '/\.(css)(\?.*)?$/i',
            '/\/css\//',
            '/\/styles\//',
            
            // JavaScript (we don't need interactive features)
            '/\.(js)(\?.*)?$/i',
            '/\/js\//',
            '/\/javascript\//',
            
            // Fonts
            '/\.(woff|woff2|ttf|eot|otf)(\?.*)?$/i',
            '/\/fonts\//',
            
            // Analytics and tracking
            '/google-analytics/',
            '/googletagmanager/',
            '/facebook\.net/',
            '/doubleclick\.net/',
            '/amazon-adsystem/',
            '/assoc-amazon/',
            '/analytics/',
            '/tracking/',
            '/metrics/',
            '/telemetry/',
            
            // Ads and recommendations
            '/\/ads\//',
            '/\/advertising\//',
            '/\/recommendations\//',
            '/\/sponsored\//',
            
            // Amazon-specific heavy resources
            '/\/gp\/video\//',
            '/\/gp\/music\//',
            '/\/gp\/photos\//',
            '/\/gp\/kindle\//',
            '/\/api\//',
            '/\/ajax\//',
            '/\/widget\//',
            '/\/personalization\//',
            
            // Third-party resources
            '/twitter\.com/',
            '/instagram\.com/',
            '/youtube\.com/',
            '/facebook\.com/',
            '/pinterest\.com/',
        ];
        
        foreach ($blockedPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Make a bandwidth-optimized request with compression and size limits.
     */
    private function makeOptimizedRequest(string $url, array $options = []): ?\Psr\Http\Message\ResponseInterface
    {
        // Check if we should block this URL entirely
        if ($this->shouldBlockUrl($url)) {
            LoggingService::log('Blocked resource to save bandwidth', [
                'url' => $url,
                'reason' => 'matches_blocked_pattern'
            ]);
            
            return null;
        }
        
        // Check if we should use direct connection for non-critical requests
        $useDirectConnection = $this->shouldUseDirectConnection($url);
        
        if ($useDirectConnection) {
            return $this->makeDirectRequest($url, $options);
        }
        
        // Add bandwidth optimization options
        $optimizedOptions = array_merge($options, [
            'headers' => array_merge($options['headers'] ?? [], [
                'Accept-Encoding' => 'gzip, deflate, br', // Force compression
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', // Prioritize HTML
            ]),
            'stream' => false, // Don't stream large responses
            'timeout' => 30, // Reasonable timeout
            'read_timeout' => 30, // Prevent hanging on large responses
            'curl' => [
                CURLOPT_ENCODING => 'gzip, deflate', // Force compression at curl level
                CURLOPT_MAXFILESIZE => 3 * 1024 * 1024, // 3MB hard limit
                CURLOPT_BUFFERSIZE => 16384, // 16KB buffer for faster processing
                CURLOPT_NOPROGRESS => false, // Enable progress tracking
                CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded) {
                    // Abort if response is getting too large
                    if ($downloaded > 3 * 1024 * 1024) { // 3MB limit
                        LoggingService::log('Aborting request - response too large', [
                            'downloaded' => $this->formatBytes($downloaded),
                            'limit' => '3MB'
                        ]);
                        return 1; // Abort
                    }
                    return 0; // Continue
                },
            ],
        ]);
        
        try {
            $startTime = microtime(true);
            $response = $this->httpClient->get($url, $optimizedOptions);
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $body = $response->getBody()->getContents();
            $contentLength = strlen($body);
            
            // Check if response is too large
            $maxSize = 3 * 1024 * 1024; // 3MB
            if ($contentLength > $maxSize) {
                LoggingService::log('Response too large, truncating', [
                    'url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
                    'original_size' => $this->formatBytes($contentLength),
                    'max_size' => $this->formatBytes($maxSize),
                    'truncated' => 'yes'
                ]);
                
                // Truncate response to max size
                $body = substr($body, 0, $maxSize);
                $contentLength = strlen($body);
            }
            
            // Check compression ratio
            $originalSize = $response->getHeader('Content-Length')[0] ?? $contentLength;
            $compressionRatio = $originalSize > 0 ? (($originalSize - $contentLength) / $originalSize) * 100 : 0;
            
            LoggingService::log('Optimized request completed', [
                'url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
                'response_time_ms' => round($responseTime, 2),
                'content_length' => $this->formatBytes($contentLength),
                'compression_ratio' => round($compressionRatio, 1) . '%',
                'status' => $response->getStatusCode()
            ]);
            
            // Log bandwidth usage
            $this->logBandwidthUsage($url, $contentLength);
            
            // Create a new response with the potentially truncated body
            $response = $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($body));
            
            return $response;
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            LoggingService::log('Optimized request failed', [
                'url' => $url,
                'error' => $errorMessage
            ]);
            
            // Check if this is a proxy authentication error
            if (str_contains($errorMessage, 'cURL error 56') || 
                str_contains($errorMessage, 'Received HTTP code 407') ||
                str_contains($errorMessage, 'proxy authentication')) {
                
                LoggingService::log('Proxy authentication error detected', [
                    'url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
                    'error_type' => 'proxy_auth_failure',
                    'proxy_provider' => $this->currentProxyConfig['provider'] ?? 'unknown'
                ]);
                
                // Send alert about proxy service issues (for admin)
                app(AlertService::class)->proxyServiceIssue(
                    'Proxy authentication failed',
                    [
                        'error' => $errorMessage,
                        'provider' => $this->currentProxyConfig['provider'] ?? 'unknown',
                        'url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH)
                    ]
                );
            }
            
            return null;
        }
    }

    /**
     * Log bandwidth usage for monitoring.
     */
    private function logBandwidthUsage(string $url, int $bytes): void
    {
        // Simple logging without alerts or limits - let the proxy service handle monitoring
        LoggingService::log('Bandwidth usage logged', [
            'url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
            'bytes' => $bytes,
            'bytes_formatted' => $this->formatBytes($bytes)
        ]);
    }

    /**
     * Format bytes for human-readable display.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Check if we should use direct connection for non-critical requests.
     */
    private function shouldUseDirectConnection(string $url): bool
    {
        // Removed bandwidth-based direct connection logic
        // Let the proxy service handle its own bandwidth management
        return false;
    }

    /**
     * Make a direct request without proxy (for non-critical requests).
     */
    private function makeDirectRequest(string $url, array $options = []): ?\Psr\Http\Message\ResponseInterface
    {
        try {
            // Create a temporary direct client
            $directClient = new Client([
                'timeout' => 30,
                'connect_timeout' => 15,
                'http_errors' => false,
                'verify' => false,
                'cookies' => $this->cookieJar, // Still use cookies
                'headers' => $this->getBandwidthOptimizedHeaders(),
            ]);
            
            $response = $directClient->get($url, $options);
            
            LoggingService::log('Direct request completed', [
                'url' => parse_url($url, PHP_URL_PATH),
                'status' => $response->getStatusCode(),
                'bandwidth_saved' => 'proxy_bypassed'
            ]);
            
            return $response;
            
        } catch (\Exception $e) {
            LoggingService::log('Direct request failed, falling back to proxy', [
                'url' => parse_url($url, PHP_URL_PATH),
                'error' => $e->getMessage()
            ]);
            
            // Fall back to proxy request
            return null;
        }
    }

    /**
     * Set HTTP client for testing purposes.
     */
    public function setHttpClient(Client $client): void
    {
        $this->httpClient = $client;
    }

    /**
     * Setup cookies from environment configuration.
     */
    private function setupCookies(): void
    {
        $cookieString = env('AMAZON_COOKIES', '');
        
        if (empty($cookieString)) {
            LoggingService::log('No Amazon cookies configured in AMAZON_COOKIES environment variable');
            return;
        }

        // Parse cookie string format: "name1=value1; name2=value2; name3=value3"
        $cookies = explode(';', $cookieString);
        
        foreach ($cookies as $cookie) {
            $cookie = trim($cookie);
            if (empty($cookie)) continue;
            
            $parts = explode('=', $cookie, 2);
            if (count($parts) !== 2) continue;
            
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            
            $this->cookieJar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => '.amazon.com',
                'Path' => '/',
                'Secure' => true,
                'HttpOnly' => true,
            ]));
        }
        
        LoggingService::log('Loaded ' . count($cookies) . ' Amazon cookies for scraping');
    }

    /**
     * Fetch Amazon reviews and save to database.
     *
     * @param string $asin       Amazon Standard Identification Number
     * @param string $country    Two-letter country code
     * @param string $productUrl Full Amazon product URL
     *
     * @throws \Exception If product doesn't exist or fetching fails
     *
     * @return AsinData The created database record
     */
    public function fetchReviewsAndSave(string $asin, string $country, string $productUrl): AsinData
    {
        // Fetch reviews from Amazon with direct scraping
        $reviewsData = $this->fetchReviews($asin, $country);

        // Check if fetching failed and provide specific error message
        if (empty($reviewsData) || !isset($reviewsData['reviews'])) {
            // Create a more descriptive exception that will be properly handled by LoggingService
            $exception = new \Exception('Unable to fetch product reviews. This could be due to Amazon blocking requests, network issues, or service configuration problems. Please try again in a few minutes.');
            
            // Let LoggingService handle the exception and provide appropriate user message
            throw new \Exception(LoggingService::handleException($exception));
        }

        // Save to database - NO OpenAI analysis yet (will be done separately)
        return AsinData::create([
            'asin'                => $asin,
            'country'             => $country,
            'product_description' => $reviewsData['description'] ?? '',
            'reviews'             => json_encode($reviewsData['reviews']),
            'openai_result'       => null, // Will be populated later
        ]);
    }

    /**
     * Fetch reviews from Amazon by scraping.
     *
     * @param string $asin    Amazon Standard Identification Number
     * @param string $country Two-letter country code (defaults to 'us')
     *
     * @return array<string, mixed> Array containing reviews, description, and total count
     */
    public function fetchReviews(string $asin, string $country = 'us'): array
    {
        // Basic ASIN format validation
        if (!$this->isValidAsinFormat($asin)) {
            LoggingService::log('ASIN format validation failed', ['asin' => $asin]);
            return [];
        }

        LoggingService::log('Starting Amazon scraping for ASIN: ' . $asin);

        try {
            // First, get the product page to extract basic info
            $productData = $this->scrapeProductPage($asin, $country);
            
            if (empty($productData)) {
                LoggingService::log('Failed to scrape product page', ['asin' => $asin]);
                return [];
            }

            // Then scrape reviews from multiple pages
            $reviews = $this->scrapeReviewPages($asin, $country);
            
            if (empty($reviews)) {
                LoggingService::log('No reviews found for ASIN: ' . $asin);
                
                // Check if this might be due to cookie expiration
                if ($this->detectCookieExpiration($asin)) {
                    app(AlertService::class)->amazonSessionExpired(
                        'Amazon scraping session may have expired - no reviews found',
                        [
                            'asin' => $asin,
                            'service' => 'direct_scraping',
                            'method' => 'fetchReviews'
                        ]
                    );
                }
                
                return [];
            }

            $result = [
                'reviews' => $reviews,
                'description' => $productData['description'] ?? '',
                'total_reviews' => count($reviews),
            ];

            LoggingService::log('Successfully scraped ' . count($reviews) . ' reviews for ASIN: ' . $asin);
            
            return $result;

        } catch (\Exception $e) {
            LoggingService::log('Amazon scraping failed', [
                'asin' => $asin,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Send alert for scraping failures
            app(AlertService::class)->connectivityIssue(
                'Amazon Direct Scraping',
                'SCRAPING_FAILED',
                $e->getMessage(),
                ['asin' => $asin]
            );

            return [];
        }
    }

    /**
     * Scrape the main product page for basic information with smart caching.
     */
    private function scrapeProductPage(string $asin, string $country): array
    {
        $url = "https://www.amazon.com/dp/{$asin}";
        
        // Check cache first (product pages don't change often)
        $cacheKey = "product_page_{$asin}_{$country}";
        $cachedData = Cache::get($cacheKey);
        
        if ($cachedData) {
            LoggingService::log('Using cached product page data', [
                'asin' => $asin,
                'cache_key' => $cacheKey,
                'bandwidth_saved' => 'yes'
            ]);
            
            return $cachedData;
        }
        
        LoggingService::log('Scraping product page: ' . $url);
        
        try {
            $response = $this->makeOptimizedRequest($url, [
                'headers' => array_merge($this->getBandwidthOptimizedHeaders(), [
                    'Referer' => 'https://www.amazon.com/',
                    'If-Modified-Since' => gmdate('D, d M Y H:i:s T', strtotime('-6 hours')), // Only get if modified in last 6 hours
                ])
            ]);

            if (!$response) {
                LoggingService::log('Product page request was blocked for bandwidth optimization', ['asin' => $asin]);
                return [];
            }

            $statusCode = $response->getStatusCode();
            
            // Handle 304 Not Modified - use cached data if available
            if ($statusCode === 304) {
                LoggingService::log('Product page not modified, using cached data', [
                    'asin' => $asin,
                    'bandwidth_saved' => 'yes'
                ]);
                return $cachedData ?? [];
            }
            
            $html = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                LoggingService::log('Product page returned non-200 status', [
                    'status' => $statusCode,
                    'asin' => $asin
                ]);
                return [];
            }

            if (empty($html)) {
                LoggingService::log('Empty response from product page', ['asin' => $asin]);
                return [];
            }

            // Parse HTML with DOMCrawler
            $crawler = new Crawler($html);
            
            // Extract product description/title
            $description = '';
            
            // Try multiple selectors for product title
            $titleSelectors = [
                '#productTitle',
                '.product-title',
                '[data-automation-id="product-title"]',
                'h1.a-size-large'
            ];
            
            foreach ($titleSelectors as $selector) {
                try {
                    $titleNode = $crawler->filter($selector);
                    if ($titleNode->count() > 0) {
                        $description = trim($titleNode->text());
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            if (empty($description)) {
                LoggingService::log('Could not extract product title', ['asin' => $asin]);
                $description = "Product {$asin}";
            }

            $result = [
                'description' => $description,
                'asin' => $asin,
            ];
            
            // Cache the result for 6 hours (product titles rarely change)
            Cache::put($cacheKey, $result, 6 * 60 * 60);
            
            LoggingService::log('Cached product page data', [
                'asin' => $asin,
                'cache_duration' => '6 hours'
            ]);

            return $result;

        } catch (\Exception $e) {
            LoggingService::log('Failed to scrape product page', [
                'asin' => $asin,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Scrape reviews from multiple review pages.
     */
    private function scrapeReviewPages(string $asin, string $country, int $maxPages = 10): array
    {
        $allReviews = [];
        
        // Try different Amazon review URL patterns, prioritizing the most reliable
        $urlPatterns = [
            "https://www.amazon.com/gp/product/{$asin}/reviews", // Most reliable
            "https://www.amazon.com/dp/product-reviews/{$asin}",
            "https://www.amazon.com/product-reviews/{$asin}",
        ];
        
        $workingBaseUrl = null;
        
        // Test which URL pattern works and has substantial content
        foreach ($urlPatterns as $pattern) {
            try {
                $testResponse = $this->makeOptimizedRequest($pattern, [
                    'headers' => array_merge($this->getBandwidthOptimizedHeaders(), [
                        'Referer' => "https://www.amazon.com/dp/{$asin}",
                    ])
                ]);
                
                if (!$testResponse) {
                    LoggingService::log("URL pattern blocked for bandwidth optimization: {$pattern}", ['asin' => $asin]);
                    continue;
                }
                
                $statusCode = $testResponse->getStatusCode();
                $contentLength = strlen($testResponse->getBody()->getContents());
                
                // URL should return 200 and have substantial content (likely contains reviews)
                if ($statusCode === 200 && $contentLength > 2000) {
                    $workingBaseUrl = $pattern;
                    LoggingService::log("Found working reviews URL pattern: {$pattern}", [
                        'asin' => $asin,
                        'status' => $statusCode,
                        'content_length' => $contentLength
                    ]);
                    break;
                }
                
                LoggingService::log("URL pattern insufficient: {$pattern}", [
                    'asin' => $asin,
                    'status' => $statusCode,
                    'content_length' => $contentLength
                ]);
                
            } catch (\Exception $e) {
                LoggingService::log("URL pattern failed: {$pattern}", [
                    'asin' => $asin,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        if (!$workingBaseUrl) {
            LoggingService::log("No working review URL pattern found", ['asin' => $asin]);
            return [];
        }
        
        for ($page = 1; $page <= $maxPages; $page++) {
            LoggingService::log("Scraping reviews page {$page} for ASIN: {$asin}");
            
            $url = $workingBaseUrl . "?pageNumber={$page}&sortBy=recent";
            
            $retryCount = 0;
            $maxRetries = 3;
            
            while ($retryCount < $maxRetries) {
                try {
                    $response = $this->makeOptimizedRequest($url, [
                        'headers' => array_merge($this->getBandwidthOptimizedHeaders(), [
                            'Referer' => $page === 1 ? "https://www.amazon.com/dp/{$asin}" : $workingBaseUrl,
                        ])
                    ]);

                    if (!$response) {
                        LoggingService::log("Review page request blocked for bandwidth optimization", [
                            'asin' => $asin,
                            'page' => $page,
                            'retry' => $retryCount + 1
                        ]);
                        $retryCount++;
                        continue;
                    }

                    $statusCode = $response->getStatusCode();
                    $html = $response->getBody()->getContents();

                    if ($statusCode === 200 && !empty($html)) {
                        // Success - report to proxy manager
                        if ($this->currentProxyConfig) {
                            $this->proxyManager->reportSuccess($this->currentProxyConfig);
                        }
                        
                        // Parse reviews from this page
                        $pageReviews = $this->parseReviewsFromHtml($html, $asin);
                        
                        if (empty($pageReviews)) {
                            LoggingService::log("No reviews found on page {$page}, stopping", ['asin' => $asin]);
                            break 2; // Break out of both loops
                        }

                        $allReviews = array_merge($allReviews, $pageReviews);
                        
                        LoggingService::log("Extracted " . count($pageReviews) . " reviews from page {$page}");
                        break; // Success, exit retry loop
                        
                    } else {
                        // Handle non-200 status or empty response
                        LoggingService::log("Reviews page {$page} returned non-200 status or empty response", [
                            'status' => $statusCode,
                            'asin' => $asin,
                            'content_length' => strlen($html),
                            'retry' => $retryCount + 1
                        ]);
                        
                        // Check if this looks like blocking
                        if ($statusCode === 503 || $statusCode === 429 || strpos($html, 'blocked') !== false) {
                            $this->handleBlocking($asin, $statusCode, $html);
                            
                            // If we have a session-based proxy, rotate session and reinitialize client
                            if ($this->currentProxyConfig && 
                                isset($this->currentProxyConfig['session_id']) && 
                                $this->currentProxyConfig['session_id']) {
                                
                                LoggingService::log('Rotating proxy session due to blocking', [
                                    'asin' => $asin,
                                    'status' => $statusCode,
                                    'retry' => $retryCount + 1
                                ]);
                                
                                $this->proxyManager->rotateSession();
                                $this->initializeHttpClient(); // Reinitialize with new session
                            }
                            
                            $retryCount++;
                            continue;
                        }
                        
                        break 2; // Non-retryable error, exit both loops
                    }

                } catch (\Exception $e) {
                    LoggingService::log("Failed to scrape reviews page {$page}", [
                        'asin' => $asin,
                        'error' => $e->getMessage(),
                        'retry' => $retryCount + 1
                    ]);
                    
                    // Report failure to proxy manager
                    if ($this->currentProxyConfig) {
                        $this->proxyManager->reportFailure($this->currentProxyConfig, $e->getMessage());
                    }
                    
                    $retryCount++;
                    if ($retryCount < $maxRetries) {
                        // Rotate proxy and retry
                        $this->rotateProxyAndReconnect();
                        usleep(1000000); // 1 second delay before retry
                    }
                }
            }
            
            if ($retryCount >= $maxRetries) {
                LoggingService::log("Max retries reached for page {$page}, stopping", ['asin' => $asin]);
                break;
            }

            // Add delay between requests to avoid rate limiting
            usleep(rand(500000, 1500000)); // Random delay between 0.5-1.5 seconds
        }

        return $allReviews;
    }

    /**
     * Parse reviews from HTML content.
     */
    private function parseReviewsFromHtml(string $html, string $asin): array
    {
        $reviews = [];
        
        try {
            $crawler = new Crawler($html);
            
            // Find review containers - Amazon uses various selectors
            $reviewSelectors = [
                '[data-hook="review"]',
                '.review',
                '.cr-original-review-item',
                '[data-hook="review-body"]'
            ];
            
            $reviewNodes = null;
            foreach ($reviewSelectors as $selector) {
                try {
                    $nodes = $crawler->filter($selector);
                    if ($nodes->count() > 0) {
                        $reviewNodes = $nodes;
                        LoggingService::log('Found review nodes with selector', [
                            'asin' => $asin,
                            'selector' => $selector,
                            'count' => $nodes->count()
                        ]);
                        break;
                    }
                } catch (\Exception $e) {
                    LoggingService::log('Selector failed', [
                        'asin' => $asin,
                        'selector' => $selector,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!$reviewNodes || $reviewNodes->count() === 0) {
                LoggingService::log('No review nodes found in HTML', [
                    'asin' => $asin,
                    'html_length' => strlen($html),
                    'selectors_tried' => $reviewSelectors,
                    'html_sample' => substr($html, 0, 500) . '...'
                ]);
                return [];
            }

            $reviewNodes->each(function (Crawler $node) use (&$reviews) {
                try {
                    $review = $this->extractReviewFromNode($node);
                    if (!empty($review)) {
                        $reviews[] = $review;
                    }
                } catch (\Exception $e) {
                    LoggingService::log('Failed to extract review from node', [
                        'error' => $e->getMessage()
                    ]);
                }
            });

        } catch (\Exception $e) {
            LoggingService::log('Failed to parse reviews from HTML', [
                'asin' => $asin,
                'error' => $e->getMessage()
            ]);
        }

        return $reviews;
    }

    /**
     * Extract individual review data from a review node.
     */
    private function extractReviewFromNode(Crawler $node): array
    {
        $review = [];

        try {
            // Extract rating
            $ratingSelectors = [
                '.review-rating .a-icon-alt',
                '[data-hook="review-star-rating"] .a-icon-alt',
                '.cr-original-review-item .review-rating .a-icon-alt'
            ];
            
            $rating = 0;
            foreach ($ratingSelectors as $selector) {
                try {
                    $ratingNode = $node->filter($selector);
                    if ($ratingNode->count() > 0) {
                        $ratingText = $ratingNode->text();
                        if (preg_match('/(\d+(?:\.\d+)?)/', $ratingText, $matches)) {
                            $rating = (float) $matches[1];
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            // Extract review title
            $titleSelectors = [
                '[data-hook="review-title"] span',
                '.review-title',
                '.cr-original-review-item .review-title'
            ];
            
            $title = '';
            foreach ($titleSelectors as $selector) {
                try {
                    $titleNode = $node->filter($selector);
                    if ($titleNode->count() > 0) {
                        $title = trim($titleNode->text());
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            // Extract review text
            $textSelectors = [
                '[data-hook="review-body"] [data-hook="review-collapsed"] span',
                '[data-hook="review-body"] .reviewText span',
                '[data-hook="review-body"] span',
                '.review-text',
                '.cr-original-review-item .review-text'
            ];
            
            $text = '';
            foreach ($textSelectors as $selector) {
                try {
                    $textNode = $node->filter($selector);
                    if ($textNode->count() > 0) {
                        $text = trim($textNode->text());
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            // Extract author
            $authorSelectors = [
                '[data-hook="review-author"] .a-profile-name',
                '.review-byline .a-profile-name',
                '.cr-original-review-item .review-byline .a-profile-name'
            ];
            
            $author = '';
            foreach ($authorSelectors as $selector) {
                try {
                    $authorNode = $node->filter($selector);
                    if ($authorNode->count() > 0) {
                        $author = trim($authorNode->text());
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            // Only include review if we have essential data
            if ($rating > 0 && !empty($text)) {
                $review = [
                    'id' => 'scrape_' . uniqid(), // Generate unique ID for OpenAI processing
                    'rating' => $rating,
                    'review_title' => $title ?: '', // Use extracted title or empty string
                    'review_text' => $text,
                    'author' => $author ?: 'Anonymous',
                    // Keep original fields for backward compatibility
                    'text' => $text,
                ];
            }

        } catch (\Exception $e) {
            LoggingService::log('Error extracting review data', [
                'error' => $e->getMessage()
            ]);
        }

        return $review;
    }

    /**
     * Validate ASIN format without hitting Amazon servers.
     */
    private function isValidAsinFormat(string $asin): bool
    {
        // ASIN should be exactly 10 characters, alphanumeric
        return preg_match('/^[A-Z0-9]{10}$/', $asin) === 1;
    }

    /**
     * Detect if cookie session might have expired.
     */
    private function detectCookieExpiration(string $asin): bool
    {
        // Try to access a simple Amazon page to test cookie validity
        try {
            $response = $this->httpClient->get('https://www.amazon.com/gp/css/homepage.html', [
                'timeout' => 10
            ]);
            
            $html = $response->getBody()->getContents();
            
            // Check for signs that we need to sign in
            $signInIndicators = [
                'sign in',
                'sign-in',
                'ap-signin',
                'authentication required',
                'session expired'
            ];
            
            $htmlLower = strtolower($html);
            foreach ($signInIndicators as $indicator) {
                if (strpos($htmlLower, $indicator) !== false) {
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            LoggingService::log('Cookie expiration detection failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Handle blocking detection and response.
     */
    private function handleBlocking(string $asin, int $statusCode, string $html): void
    {
        LoggingService::log('Potential blocking detected', [
            'asin' => $asin,
            'status_code' => $statusCode,
            'content_length' => strlen($html),
            'proxy_type' => $this->currentProxyConfig['type'] ?? 'direct'
        ]);
        
        // Check for specific blocking indicators
        $blockingIndicators = [
            'blocked',
            'captcha',
            'unusual traffic',
            'automated requests',
            'try again later',
            'service unavailable',
            'rate limit'
        ];
        
        $htmlLower = strtolower($html);
        foreach ($blockingIndicators as $indicator) {
            if (strpos($htmlLower, $indicator) !== false) {
                LoggingService::log("Blocking indicator found: {$indicator}", ['asin' => $asin]);
                
                // Rotate proxy and session
                $this->rotateProxyAndReconnect();
                break;
            }
        }
    }
    
    /**
     * Rotate proxy and reconnect HTTP client.
     */
    private function rotateProxyAndReconnect(): void
    {
        LoggingService::log('Rotating proxy due to blocking/failure');
        
        // Rotate session if supported
        $this->proxyManager->rotateSession();
        
        // Reinitialize HTTP client with new proxy
        $this->initializeHttpClient();
        
        // Add extra delay after rotation
        usleep(2000000); // 2 second delay
    }
    
    /**
     * Get proxy statistics for monitoring.
     */
    public function getProxyStats(): array
    {
        return $this->proxyManager->getProxyStats();
    }
} 