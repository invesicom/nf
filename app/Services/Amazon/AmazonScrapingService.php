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
            
            // Add specific proxy settings for Amazon with PROGRESSIVE bandwidth optimization
            $clientConfig['curl'] = [
                CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_ENCODING => 'gzip, deflate', // Force compression
                CURLOPT_MAXFILESIZE => 3 * 1024 * 1024, // 3MB limit - allow enough for complete HTML before filtering
                CURLOPT_BUFFERSIZE => 8192, // 8KB buffer for faster processing (reduced from 16KB)
            ];
            
            LoggingService::log('Using proxy for Amazon scraping with PROGRESSIVE bandwidth optimization', [
                'type' => $this->currentProxyConfig['type'],
                'provider' => $this->currentProxyConfig['provider'] ?? 'custom',
                'country' => $this->currentProxyConfig['country'],
                'session_id' => $this->currentProxyConfig['session_id'] ?? 'none',
                'max_download_size' => '3MB',
                'filtering_applied' => 'smart_html_filtering',
                'compression' => 'gzip, deflate',
                'optimization_level' => 'progressive'
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
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,application/xml;q=0.1', // Heavily prioritize HTML, de-prioritize other formats
            'Accept-Language' => 'en-US,en;q=0.5', // Simplified language preferences to reduce header size
            'Accept-Encoding' => 'gzip, deflate, br', // Force compression
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'DNT' => '1',
            // AGGRESSIVE bandwidth optimization headers
            'Save-Data' => '1', // Request reduced data usage (Chrome feature)
            'Viewport-Width' => '1024', // Optimize for smaller viewport to get smaller images
            'DPR' => '1', // Device pixel ratio = 1 (no high-DPI images)
            'Width' => '1024', // Request smaller image widths
            'Downlink' => '0.5', // Hint that we have slow connection (encourage smaller resources)
            'ECT' => 'slow-2g', // Effective Connection Type hint for reduced content
            'RTT' => '2000', // Round Trip Time hint (slower = less content)
            'Cache-Control' => 'max-age=0, no-cache', // Prevent large cached responses
            'Pragma' => 'no-cache', // HTTP/1.0 cache control
            // Removed unnecessary headers to reduce request size:
            // - Sec-Fetch-User (not essential)
            // - Sec-GPC (privacy header, not needed for scraping)
            // - Cache-Control max-age (conflicts with no-cache)
        ];
    }

    /**
     * Check if a URL should be blocked to save bandwidth.
     */
    private function shouldBlockUrl(string $url): bool
    {
        // Block common resource-heavy URLs that we don't need for review scraping
        // Enhanced patterns for maximum bandwidth savings
        $blockedPatterns = [
            // Images and media (CRITICAL - these are often the largest bandwidth consumers)
            '/\.(jpg|jpeg|png|gif|webp|svg|ico|bmp|tiff|tif)(\?.*)?$/i',
            '/\.(mp4|mp3|avi|mov|wmv|flv|webm|ogg|wav|m4a)(\?.*)?$/i',
            '/\/images\//',
            '/\/media\//',
            '/\/img\//',
            '/\.cloudfront\.net.*\.(jpg|jpeg|png|gif|webp)/',
            '/\/product-images\//',
            '/\/product-media\//',
            
            // CSS and styling (we don't need visual styling)
            '/\.(css)(\?.*)?$/i',
            '/\/css\//',
            '/\/styles\//',
            '/\/stylesheets\//',
            '/\/assets\/.*\.css/',
            
            // JavaScript (we don't need interactive features - MAJOR bandwidth saver)
            '/\.(js)(\?.*)?$/i',
            '/\/js\//',
            '/\/javascript\//',
            '/\/assets\/.*\.js/',
            '/\.min\.js/',
            '/\/scripts\//',
            
            // Fonts (unnecessary for scraping)
            '/\.(woff|woff2|ttf|eot|otf)(\?.*)?$/i',
            '/\/fonts\//',
            '/\/webfonts\//',
            
            // Analytics and tracking (BANDWIDTH WASTE - block all)
            '/google-analytics/',
            '/googletagmanager/',
            '/googlesyndication/',
            '/facebook\.net/',
            '/doubleclick\.net/',
            '/amazon-adsystem/',
            '/assoc-amazon/',
            '/analytics/',
            '/tracking/',
            '/metrics/',
            '/telemetry/',
            '/gtag/',
            '/gtm\.js/',
            '/fbpixel/',
            '/pixel\.facebook/',
            '/scorecardresearch/',
            '/quantserve/',
            '/newrelic/',
            
            // Ads and recommendations (MAJOR bandwidth waste)
            '/\/ads\//',
            '/\/advertising\//',
            '/\/recommendations\//',
            '/\/sponsored\//',
            '/\/banners\//',
            '/\/promo\//',
            '/\/deals\//',
            '/\/offers\//',
            '/adsystem\.amazon/',
            '/amazonclix/',
            
            // Amazon-specific heavy resources we don't need
            '/\/gp\/video\//',
            '/\/gp\/music\//',
            '/\/gp\/photos\//',
            '/\/gp\/kindle\//',
            '/\/gp\/prime\//',
            '/\/gp\/cart\//',
            '/\/gp\/checkout\//',
            '/\/gp\/buy\//',
            '/\/gp\/history\//',
            '/\/gp\/yourstore\//',
            '/\/gp\/registry\//',
            '/\/gp\/wishlist\//',
            '/\/api\//',
            '/\/ajax\//',
            '/\/widget\//',
            '/\/personalization\//',
            '/\/recommendations\//',
            '/\/similar\//',
            '/\/related\//',
            '/\/search\//',
            '/\/autocomplete\//',
            
            // Third-party resources (social media, external widgets)
            '/twitter\.com/',
            '/instagram\.com/',
            '/youtube\.com/',
            '/facebook\.com/',
            '/pinterest\.com/',
            '/linkedin\.com/',
            '/tiktok\.com/',
            '/snapchat\.com/',
            '/reddit\.com/',
            
            // Additional Amazon-specific wasteful resources
            '/\/alexa\//',
            '/\/premium\//',
            '/\/subscribe\//',
            '/\/live\//',
            '/\/stream\//',
            '/\/video\//',
            '/\/audio\//',
            '/\/game\//',
            '/\/app\//',
            '/\/mobile\//',
            '/\/tablet\//',
            '/\/desktop\//',
            
            // XML/JSON feeds we don't need
            '/\.xml(\?.*)?$/i',
            '/\.json(\?.*)?$/i',
            '/\/feed\//',
            '/\/rss\//',
            '/\/sitemap/',
            
            // PDFs and documents
            '/\.(pdf|doc|docx|xls|xlsx|ppt|pptx)(\?.*)?$/i',
            
            // Compressed files
            '/\.(zip|rar|tar|gz|7z)(\?.*)?$/i',
            
            // Map files and source maps
            '/\.map(\?.*)?$/i',
            '/sourcemap/',
            
            // Favicon and manifest files (unless essential)
            '/favicon\.ico/',
            '/apple-touch-icon/',
            '/manifest\.json/',
            '/browserconfig\.xml/',
            
            // Security and verification files
            '/robots\.txt/',
            '/security\.txt/',
            '/\.well-known\//',
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
        
        // Add aggressive bandwidth optimization options
        $optimizedOptions = array_merge($options, [
            'headers' => array_merge($options['headers'] ?? [], [
                'Accept-Encoding' => 'gzip, deflate, br', // Force compression
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1', // Heavily prioritize HTML only
                'Cache-Control' => 'max-age=0, no-cache', // Prevent large cached responses
                'Pragma' => 'no-cache', // HTTP/1.0 cache control
            ]),
            'stream' => false, // Don't stream large responses
            'timeout' => 25, // Reduced timeout to prevent long downloads
            'read_timeout' => 25, // Prevent hanging on large responses
            'curl' => [
                CURLOPT_ENCODING => 'gzip, deflate', // Force compression at curl level
                CURLOPT_MAXFILESIZE => 3 * 1024 * 1024, // 3MB hard limit (allow complete HTML download before filtering)
                CURLOPT_BUFFERSIZE => 8192, // 8KB buffer for faster processing (reduced from 16KB)
                CURLOPT_NOPROGRESS => false, // Enable progress tracking
                CURLOPT_PROGRESSFUNCTION => function($resource, $download_size, $downloaded, $upload_size, $uploaded) {
                    // Allow complete HTML download - filtering will reduce size after
                    if ($downloaded > 3 * 1024 * 1024) { // 3MB limit - enough for complete page
                        LoggingService::log('Aborting request - response too large', [
                            'downloaded' => $this->formatBytes($downloaded),
                            'limit' => '3MB',
                            'bandwidth_optimization' => 'progressive_size_limit'
                        ]);
                        return 1; // Abort
                    }
                    return 0; // Continue
                },
                // Additional curl options for bandwidth optimization
                CURLOPT_LOW_SPEED_LIMIT => 1024, // Minimum 1KB/s transfer rate
                CURLOPT_LOW_SPEED_TIME => 10, // Abort if slower than 1KB/s for 10 seconds
                CURLOPT_MAXCONNECTS => 1, // Limit connection pool
                CURLOPT_FRESH_CONNECT => false, // Reuse connections when possible
            ],
        ]);
        
        try {
            $startTime = microtime(true);
            $response = $this->httpClient->get($url, $optimizedOptions);
            $endTime = microtime(true);
            
            $responseTime = ($endTime - $startTime) * 1000; // Convert to milliseconds
            $body = $response->getBody()->getContents();
            $originalSize = strlen($body);
            
            // SMART content filtering instead of arbitrary truncation
            $filteredBody = $this->filterHtmlForBandwidthOptimization($body);
            $finalSize = strlen($filteredBody);
            
            $bandwidthSaved = $originalSize - $finalSize;
            $compressionRatio = $originalSize > 0 ? ($bandwidthSaved / $originalSize) * 100 : 0;
            
            // Enhanced logging for bandwidth monitoring
            LoggingService::log('Optimized request completed', [
                'url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
                'response_time_ms' => round($responseTime, 2),
                'original_size' => $this->formatBytes($originalSize),
                'filtered_size' => $this->formatBytes($finalSize),
                'bandwidth_saved' => $this->formatBytes($bandwidthSaved),
                'compression_ratio' => round($compressionRatio, 1) . '%',
                'status' => $response->getStatusCode(),
                'optimization_method' => 'smart_content_filtering'
            ]);
            
            // Log bandwidth usage (using final filtered size)
            $this->logBandwidthUsage($url, $finalSize);
            
            // Create a new response with the filtered body
            $response = $response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($filteredBody));
            
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
     * Filter HTML content to reduce bandwidth while preserving review data.
     */
    private function filterHtmlForBandwidthOptimization(string $html): string
    {
        try {
            // First, check if this HTML contains review data - if so, be more conservative
            $hasReviews = preg_match('/data-hook=["\']review["\']/', $html) || 
                          preg_match('/class=["\'][^"\']*review[^"\']*["\']/', $html) ||
                          preg_match('/class=["\'][^"\']*cr-original-review-item[^"\']*["\']/', $html);
            
            if (!$hasReviews) {
                // If no reviews found, this might be a product page or other non-review content
                // Apply minimal filtering to preserve structure
                LoggingService::log('No review content detected, applying minimal filtering');
                return $this->applyMinimalFiltering($html);
            }
            
            LoggingService::log('Review content detected, applying careful filtering');
            
            // Use DOMDocument for precise content filtering when reviews are present
            $doc = new \DOMDocument();
            $doc->preserveWhiteSpace = false;
            $doc->formatOutput = false;
            
            // Load HTML with error suppression (Amazon HTML often has minor issues)
            libxml_use_internal_errors(true);
            
            // Try to load HTML - if it fails, fall back to minimal filtering
            // Don't add XML declaration to avoid breaking DOMCrawler later
            $loaded = $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
            if (!$loaded) {
                LoggingService::log('DOM parsing failed, using minimal filtering');
                libxml_clear_errors();
                return $this->applyMinimalFiltering($html);
            }
            
            libxml_clear_errors();
            
            $xpath = new \DOMXPath($doc);
            
            // REMOVE: Bandwidth-heavy non-essential elements (but preserve review structure)
            $removeSelectors = [
                // Scripts (major bandwidth saver) - but be careful not to break structure
                '//script[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                // Stylesheets and CSS - but preserve if needed for layout
                '//style[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//link[@rel="stylesheet"]',
                // Images (major bandwidth saver) - but preserve review-related images
                '//img[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//picture[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//figure[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                // Ads and promotional content - safe to remove (but avoid review badges)
                '//*[contains(@class, "ad") and not(contains(@class, "badge")) and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//*[contains(@class, "advertisement") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//*[contains(@id, "ad") and not(contains(@id, "badge")) and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//*[@data-hook="ads-container"]',
                // Footer and non-essential navigation - safe to remove
                '//footer[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//*[contains(@class, "footer") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                // Social media and sharing buttons - safe to remove
                '//*[contains(@class, "social") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//*[contains(@class, "share") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                // Comments and Q&A sections (not reviews) - safe to remove
                '//*[contains(@class, "askWidget")]',
                '//*[contains(@class, "qa") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                // Recommendation widgets - safe to remove
                '//*[contains(@class, "recommendation")]',
                '//*[contains(@class, "suggested")]',
                '//*[@data-hook="related-products"]',
                // Promotional banners - safe to remove
                '//*[contains(@class, "banner") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//*[contains(@class, "promo") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                // Video players - safe to remove
                '//video[not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//iframe[contains(@src, "youtube") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
                '//iframe[contains(@src, "vimeo") and not(ancestor::*[@data-hook="review"]) and not(ancestor::*[contains(@class, "review")])]',
            ];
            
            // Remove unwanted elements
            foreach ($removeSelectors as $selector) {
                $elements = $xpath->query($selector);
                if ($elements !== false) {
                    foreach ($elements as $element) {
                        if ($element->parentNode) {
                            $element->parentNode->removeChild($element);
                        }
                    }
                }
            }
            
            // Remove empty attributes to reduce size (but preserve essential ones)
            $allElements = $xpath->query('//*');
            if ($allElements !== false) {
                foreach ($allElements as $element) {
                    // Only remove non-essential attributes
                    $element->removeAttribute('style'); // CSS styles (we removed CSS anyway)
                    $element->removeAttribute('onclick'); // Event handlers
                    $element->removeAttribute('onload');
                    $element->removeAttribute('onmouseover');
                    $element->removeAttribute('onmouseout');
                    $element->removeAttribute('data-track'); // Tracking
                    $element->removeAttribute('data-analytics');
                    $element->removeAttribute('srcset'); // Image optimization
                    $element->removeAttribute('sizes');
                    
                    // DO NOT remove: data-hook, class, id - these are essential for review parsing
                }
            }
            
            $filteredHtml = $doc->saveHTML();
            
            // Clean up the HTML to ensure proper structure for DOMCrawler
            // Remove XML declaration that DOMDocument sometimes adds
            $filteredHtml = preg_replace('/<\?xml[^>]*\?>/', '', $filteredHtml);
            
            // Final cleanup - remove excessive whitespace but preserve structure
            $filteredHtml = preg_replace('/\s{2,}/', ' ', $filteredHtml);
            $filteredHtml = preg_replace('/>\s+</', '><', $filteredHtml);
            
            return $filteredHtml;
            
        } catch (\Exception $e) {
            // If filtering fails, return original but log the issue
            LoggingService::log('HTML filtering failed, using minimal filtering', [
                'error' => $e->getMessage(),
                'original_size' => $this->formatBytes(strlen($html))
            ]);
            
            return $this->applyMinimalFiltering($html);
        }
    }

    /**
     * Apply minimal filtering when DOM parsing fails or no reviews detected.
     */
    private function applyMinimalFiltering(string $html): string
    {
        // Simple regex-based filtering that's safer but less comprehensive
        $patterns = [
            // Remove script tags (major bandwidth saver)
            '/<script[^>]*>.*?<\/script>/is' => '',
            // Remove style tags
            '/<style[^>]*>.*?<\/style>/is' => '',
            // Remove comments
            '/<!--.*?-->/is' => '',
            // Remove excessive whitespace
            '/\s{3,}/' => ' ',
            '/>\s+</' => '><',
        ];
        
        $filtered = $html;
        foreach ($patterns as $pattern => $replacement) {
            $filtered = preg_replace($pattern, $replacement, $filtered);
        }
        
        return $filtered;
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
    private function scrapeReviewPages(string $asin, string $country, int $maxPages = 5): array
    {
        $allReviews = [];
        
        // Reduce bandwidth by limiting pages - 5 pages typically provides 50-100 reviews which is sufficient for analysis
        // This alone can reduce bandwidth by ~40-50% compared to 10 pages
        LoggingService::log("Starting review scraping with bandwidth optimization", [
            'asin' => $asin,
            'max_pages' => $maxPages,
            'bandwidth_optimization' => 'reduced_page_count'
        ]);
        
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
                // Lower threshold to account for HTML filtering - filtered content will be smaller
                $minContentThreshold = 1000; // 1KB after filtering is sufficient for review pages
                if ($statusCode === 200 && $contentLength > $minContentThreshold) {
                    $workingBaseUrl = $pattern;
                    LoggingService::log("Found working reviews URL pattern: {$pattern}", [
                        'asin' => $asin,
                        'status' => $statusCode,
                        'content_length' => $contentLength,
                        'threshold' => $minContentThreshold
                    ]);
                    break;
                }
                
                LoggingService::log("URL pattern insufficient: {$pattern}", [
                    'asin' => $asin,
                    'status' => $statusCode,
                    'content_length' => $contentLength,
                    'required_minimum' => $minContentThreshold
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
        
        // Track total bandwidth usage for this scraping session
        $totalBandwidthUsed = 0;
        $targetReviewCount = 30; // Target minimum for quality analysis
        
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
                    $pageSize = strlen($html);
                    $totalBandwidthUsed += $pageSize;

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
                        
                        LoggingService::log("Extracted " . count($pageReviews) . " reviews from page {$page}", [
                            'total_reviews' => count($allReviews),
                            'page_size' => $this->formatBytes($pageSize),
                            'total_bandwidth' => $this->formatBytes($totalBandwidthUsed)
                        ]);
                        
                        // INTELLIGENT early termination for bandwidth optimization
                        // Assess review quality and stop if we have sufficient data for analysis
                        if ($page >= 2) { // Only consider early termination after page 2
                            $qualityMetrics = $this->assessReviewQuality($allReviews);
                            
                            LoggingService::log("Quality assessment", [
                                'asin' => $asin,
                                'page' => $page,
                                'total_reviews' => $qualityMetrics['total_reviews'],
                                'quality_reviews' => $qualityMetrics['quality_reviews'] ?? 0,
                                'quality_score' => round($qualityMetrics['quality_score'], 1),
                                'avg_length' => round($qualityMetrics['avg_length'], 1),
                                'sufficient' => $qualityMetrics['sufficient_for_analysis']
                            ]);
                            
                            // Early termination conditions (AGGRESSIVE bandwidth savings)
                            if ($qualityMetrics['sufficient_for_analysis'] || 
                                ($qualityMetrics['quality_score'] >= 60 && count($allReviews) >= 25) ||
                                count($allReviews) >= 40) { // Hard limit to prevent excessive scraping
                                
                                $bandwidthSaved = ($maxPages - $page) * ($totalBandwidthUsed / $page);
                                
                                LoggingService::log("EARLY TERMINATION - sufficient quality data collected", [
                                    'asin' => $asin,
                                    'reviews_collected' => count($allReviews),
                                    'quality_score' => round($qualityMetrics['quality_score'], 1),
                                    'pages_scraped' => $page,
                                    'pages_remaining' => $maxPages - $page,
                                    'bandwidth_used' => $this->formatBytes($totalBandwidthUsed),
                                    'estimated_bandwidth_saved' => $this->formatBytes($bandwidthSaved),
                                    'early_termination_reason' => $qualityMetrics['sufficient_for_analysis'] ? 'quality_sufficient' : 'score_threshold'
                                ]);
                                break 2; // Exit both loops
                            }
                        }
                        
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

        LoggingService::log("Review scraping completed", [
            'asin' => $asin,
            'total_reviews' => count($allReviews),
            'pages_scraped' => min($page, $maxPages),
            'total_bandwidth_used' => $this->formatBytes($totalBandwidthUsed),
            'avg_bandwidth_per_page' => $totalBandwidthUsed > 0 ? $this->formatBytes($totalBandwidthUsed / max(1, $page - 1)) : '0 B',
            'bandwidth_optimizations' => [
                'reduced_max_pages' => '5 (from 10) = ~50% reduction',
                'smart_html_filtering' => 'Remove JS, CSS, images, ads while preserving reviews',
                'download_limit' => '3MB (was unlimited) with intelligent filtering',
                'optimized_headers' => 'Reduced header size, added bandwidth hints',
                'selective_extraction' => 'Only essential review data extracted',
                'intelligent_early_termination' => 'Quality-based stopping conditions',
                'compression_enabled' => 'gzip, deflate, br',
                'approach' => 'Progressive: Download complete HTML then filter intelligently',
                'estimated_final_savings' => '50-70% after filtering vs original approach'
            ]
        ]);

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
                        break;
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            if (!$reviewNodes || $reviewNodes->count() === 0) {
                LoggingService::log('No review nodes found in HTML', [
                    'asin' => $asin,
                    'html_length' => strlen($html),
                    'selectors_tried' => $reviewSelectors
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
                        'error' => substr($e->getMessage(), 0, 100)
                    ]);
                }
            });

        } catch (\Exception $e) {
            LoggingService::log('Failed to parse reviews from HTML', [
                'asin' => $asin,
                'error' => substr($e->getMessage(), 0, 100),
                'html_length' => strlen($html)
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
            // Extract rating - ESSENTIAL data only
            $ratingSelectors = [
                '.review-rating .a-icon-alt',
                '[data-hook="review-star-rating"] .a-icon-alt',
                '.a-icon-alt', // Broader selector for test cases
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

            // Extract review text - MOST CRITICAL for analysis
            $textSelectors = [
                '[data-hook="review-body"] [data-hook="review-collapsed"] span',
                '[data-hook="review-body"] span',
                '[data-hook="review-body"]', // Broader selector for test cases
            ];
            
            $text = '';
            foreach ($textSelectors as $selector) {
                try {
                    $textNode = $node->filter($selector);
                    if ($textNode->count() > 0) {
                        $text = trim($textNode->text());
                        if (!empty($text)) {
                            break; // Stop at first valid text found
                        }
                    }
                } catch (\Exception $e) {
                    // Continue to next selector
                }
            }

            // BANDWIDTH OPTIMIZATION: Skip extracting review title and author unless absolutely necessary
            // These are nice-to-have but not essential for sentiment analysis
            // Removing them saves processing time and reduces response parsing overhead
            
            // Only include review if we have ESSENTIAL data (rating + text)
            if ($rating > 0 && !empty($text)) {
                // SIMPLIFIED review structure - only essential fields
                $review = [
                    'id' => 'scrape_' . substr(md5($text . $rating), 0, 8), // Shorter ID for bandwidth
                    'rating' => $rating,
                    'text' => $text, // Primary field for analysis
                    'review_text' => $text, // Backward compatibility
                ];
                
                // BANDWIDTH OPTIMIZATION: Skip optional fields that increase data size
                // Removed: review_title, author, date, verified_purchase, helpful_votes
                // These can be added back if specifically needed for analysis
            }

        } catch (\Exception $e) {
            // Log extraction errors only for debugging
            LoggingService::log('Review extraction error', [
                'error' => substr($e->getMessage(), 0, 100)
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

    /**
     * Assess review quality for early termination decisions.
     */
    private function assessReviewQuality(array $reviews): array
    {
        $qualityMetrics = [
            'total_reviews' => count($reviews),
            'avg_length' => 0,
            'rating_distribution' => [],
            'quality_score' => 0,
            'sufficient_for_analysis' => false
        ];
        
        if (empty($reviews)) {
            return $qualityMetrics;
        }
        
        $totalLength = 0;
        $ratingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $qualityReviews = 0;
        
        foreach ($reviews as $review) {
            $text = $review['text'] ?? '';
            $rating = $review['rating'] ?? 0;
            
            $length = strlen($text);
            $totalLength += $length;
            
            // Count rating distribution
            $roundedRating = round($rating);
            if ($roundedRating >= 1 && $roundedRating <= 5) {
                $ratingCounts[$roundedRating]++;
            }
            
            // Quality review criteria: meaningful length and valid rating
            if ($length >= 50 && $rating > 0) { // At least 50 characters
                $qualityReviews++;
            }
        }
        
        $qualityMetrics['avg_length'] = $totalLength / count($reviews);
        $qualityMetrics['rating_distribution'] = $ratingCounts;
        $qualityMetrics['quality_reviews'] = $qualityReviews;
        
        // Calculate quality score (0-100)
        $diversityScore = count(array_filter($ratingCounts)) * 20; // Max 100 for all 5 ratings present
        $lengthScore = min(100, $qualityMetrics['avg_length'] / 2); // Max 100 for 200+ char average
        $volumeScore = min(100, $qualityReviews * 4); // Max 100 for 25+ quality reviews
        
        $qualityMetrics['quality_score'] = ($diversityScore + $lengthScore + $volumeScore) / 3;
        
        // Determine if sufficient for analysis (BANDWIDTH OPTIMIZATION)
        $qualityMetrics['sufficient_for_analysis'] = 
            $qualityReviews >= 20 && // At least 20 quality reviews
            count(array_filter($ratingCounts)) >= 3 && // At least 3 different ratings
            $qualityMetrics['avg_length'] >= 75; // Average length of 75+ characters
        
        return $qualityMetrics;
    }
} 