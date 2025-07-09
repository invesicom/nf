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
            'headers' => $this->headers,
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
            
            // Add specific proxy settings for Amazon
            $clientConfig['curl'] = [
                CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_ENCODING => '', // Accept all supported encodings
            ];
            
            LoggingService::log('Using proxy for Amazon scraping', [
                'type' => $this->currentProxyConfig['type'],
                'provider' => $this->currentProxyConfig['provider'] ?? 'custom',
                'country' => $this->currentProxyConfig['country'],
                'session_id' => $this->currentProxyConfig['session_id'] ?? 'none'
            ]);
        } else {
            LoggingService::log('No proxy configured - using direct connection');
        }
        
        $this->httpClient = new Client($clientConfig);
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
            throw new \Exception('Unable to fetch product reviews at this time. This could be due to:
• The product URL being invalid or the product not existing on Amazon
• Amazon blocking our scraping attempts (temporary)
• Network connectivity issues
• Cookie session expired

Please try again in a few minutes. If the problem persists, verify the Amazon URL is correct and the product exists on amazon.com.');
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
     * Scrape the main product page for basic information.
     */
    private function scrapeProductPage(string $asin, string $country): array
    {
        $url = "https://www.amazon.com/dp/{$asin}";
        
        LoggingService::log('Scraping product page: ' . $url);
        
        try {
            $response = $this->httpClient->get($url, [
                'headers' => array_merge($this->headers, [
                    'Referer' => 'https://www.amazon.com/',
                ])
            ]);

            $statusCode = $response->getStatusCode();
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

            return [
                'description' => $description,
                'asin' => $asin,
            ];

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
                $testResponse = $this->httpClient->get($pattern, [
                    'headers' => array_merge($this->headers, [
                        'Referer' => "https://www.amazon.com/dp/{$asin}",
                    ])
                ]);
                
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
                    $response = $this->httpClient->get($url, [
                        'headers' => array_merge($this->headers, [
                            'Referer' => $page === 1 ? "https://www.amazon.com/dp/{$asin}" : $workingBaseUrl,
                        ])
                    ]);

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