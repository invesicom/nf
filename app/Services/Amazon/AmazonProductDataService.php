<?php

namespace App\Services\Amazon;

use App\Services\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Service for scraping Amazon product data (title, description, main image).
 * 
 * This service focuses on extracting basic product information rather than reviews,
 * using multiple cookie sessions with round-robin rotation to reduce blocking.
 */
class AmazonProductDataService
{
    private Client $httpClient;
    private CookieJar $cookieJar;
    private array $headers;
    private CookieSessionManager $cookieSessionManager;
    private ?array $currentCookieSession = null;

    /**
     * Initialize the service with HTTP client configuration.
     */
    public function __construct()
    {
        $this->cookieSessionManager = new CookieSessionManager();
        $this->setupCookies();
        
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-User' => '?1',
            'Cache-Control' => 'max-age=0',
        ];

        $this->initializeHttpClient();
    }

    /**
     * Initialize HTTP client.
     */
    private function initializeHttpClient(): void
    {
        $this->httpClient = new Client([
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
            ],
        ]);
    }

    /**
     * Setup cookies using the multi-session cookie manager.
     */
    private function setupCookies(): void
    {
        // Get the next available cookie session using round-robin rotation
        $this->currentCookieSession = $this->cookieSessionManager->getNextCookieSession();
        
        if (!$this->currentCookieSession) {
            LoggingService::log('No Amazon cookie sessions available for product data scraping - falling back to legacy AMAZON_COOKIES');
            $this->setupLegacyCookies();
            return;
        }
        
        // Create cookie jar from the selected session
        $this->cookieJar = $this->cookieSessionManager->createCookieJar($this->currentCookieSession);
        
        LoggingService::log('Setup product data cookies from multi-session manager', [
            'session_name' => $this->currentCookieSession['name'],
            'session_env_var' => $this->currentCookieSession['env_var']
        ]);
    }
    
    /**
     * Fallback method to setup cookies from legacy AMAZON_COOKIES environment variable.
     */
    private function setupLegacyCookies(): void
    {
        $cookieString = env('AMAZON_COOKIES', '');
        
        if (empty($cookieString)) {
            LoggingService::log('No Amazon cookies configured for product data scraping');
            $this->cookieJar = new CookieJar();
            return;
        }

        $this->cookieJar = new CookieJar();

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
        
        LoggingService::log('Loaded ' . count($cookies) . ' Amazon cookies from legacy configuration for product data');
    }

    /**
     * Scrape product data and save to database.
     *
     * @param \App\Models\AsinData $asinData The ASIN data record to update
     * @return bool True if successful, false otherwise
     */
    public function scrapeAndSaveProductData(\App\Models\AsinData $asinData): bool
    {
        try {
            $productData = $this->scrapeProductData($asinData->asin, $asinData->country);
            
            if (empty($productData)) {
                LoggingService::log('No product data scraped', [
                    'asin' => $asinData->asin,
                    'country' => $asinData->country,
                ]);
                return false;
            }

            // Update the database record
            $asinData->update([
                'product_title' => $productData['title'] ?? null,
                'product_image_url' => $productData['image_url'] ?? null,
                'have_product_data' => true,
                'product_data_scraped_at' => now(),
            ]);

            LoggingService::log('Successfully updated product data', [
                'asin' => $asinData->asin,
                'title' => $productData['title'] ?? 'N/A',
                'has_image' => !empty($productData['image_url']),
            ]);

            return true;

        } catch (\Exception $e) {
            LoggingService::log('Failed to scrape and save product data', [
                'asin' => $asinData->asin,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Scrape product data from Amazon.
     *
     * @param string $asin Amazon Standard Identification Number
     * @param string $country Two-letter country code (defaults to 'us')
     * @return array Array containing title, description, and image_url
     */
    public function scrapeProductData(string $asin, string $country = 'us'): array
    {
        LoggingService::log('Starting Amazon product data scraping', [
            'asin' => $asin,
            'country' => $country,
        ]);

        // Skip mock data - we can extract from public Amazon pages without cookies
        // if (!$this->hasCookiesConfigured() && (app()->environment('testing') || app()->environment('local'))) {
        //     LoggingService::log('No cookies configured in test/dev environment - returning mock data', [
        //         'asin' => $asin,
        //         'environment' => app()->environment(),
        //     ]);
        //     
        //     return [
        //         'title' => "Test Product {$asin}",
        //         'description' => 'Mock product description for testing',
        //         'image_url' => 'https://via.placeholder.com/300x300?text=' . $asin,
        //     ];
        // }

        // Check cache first - product data doesn't change often
        $cacheKey = "product_data_{$asin}_{$country}";
        $cachedData = Cache::get($cacheKey);
        
        if ($cachedData) {
            LoggingService::log('Using cached product data', [
                'asin' => $asin,
                'cache_key' => $cacheKey,
            ]);
            return $cachedData;
        }

        try {
            // First try Product Advertising API if available
            $productData = $this->tryProductAdvertisingApi($asin, $country);
            
            if (empty($productData)) {
                // Fallback to web scraping
                $productData = $this->scrapeFromWebsite($asin, $country);
            }

            if (!empty($productData)) {
                // Cache the result for 6 hours
                Cache::put($cacheKey, $productData, now()->addHours(6));
                
                LoggingService::log('Successfully scraped product data', [
                    'asin' => $asin,
                    'title' => $productData['title'] ?? 'N/A',
                    'has_image' => !empty($productData['image_url']),
                ]);
            }

            return $productData;

        } catch (\Exception $e) {
            LoggingService::log('Failed to scrape product data', [
                'asin' => $asin,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Try to get product data from Amazon Product Advertising API.
     */
    private function tryProductAdvertisingApi(string $asin, string $country): array
    {
        // Check if PA-API credentials are configured
        $accessKey = env('AMAZON_PA_API_ACCESS_KEY');
        $secretKey = env('AMAZON_PA_API_SECRET_KEY');
        $partnerTag = env('AMAZON_PA_API_PARTNER_TAG');

        if (empty($accessKey) || empty($secretKey) || empty($partnerTag)) {
            LoggingService::log('Amazon PA-API credentials not configured, skipping API approach');
            return [];
        }

        LoggingService::log('Attempting to fetch product data via Amazon PA-API', [
            'asin' => $asin,
            'country' => $country,
        ]);

        // TODO: Implement PA-API integration
        // For now, we'll return empty array to fall back to scraping
        LoggingService::log('PA-API integration not yet implemented, falling back to scraping');
        return [];
    }

    /**
     * Scrape product data from Amazon website.
     */
    private function scrapeFromWebsite(string $asin, string $country): array
    {
        $url = "https://www.amazon.com/dp/{$asin}";
        
        LoggingService::log('Scraping product data from website', [
            'url' => $url,
            'asin' => $asin,
        ]);

        try {
            $response = $this->httpClient->get($url);
            $statusCode = $response->getStatusCode();
            
            if ($statusCode !== 200) {
                LoggingService::log('Non-200 status code received', [
                    'status' => $statusCode,
                    'asin' => $asin,
                ]);
                return [];
            }

            $html = $response->getBody()->getContents();
            
            if (empty($html)) {
                LoggingService::log('Empty response received', ['asin' => $asin]);
                return [];
            }

            return $this->parseProductDataFromHtml($html, $asin);

        } catch (\Exception $e) {
            LoggingService::log('Error scraping product data from website', [
                'asin' => $asin,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if cookies are configured for scraping.
     */
    private function hasCookiesConfigured(): bool
    {
        // Check if we have multi-session cookies
        if ($this->currentCookieSession !== null) {
            return true;
        }
        
        // Check if we have legacy cookies
        $legacyCookies = env('AMAZON_COOKIES', '');
        if (!empty($legacyCookies)) {
            return true;
        }
        
        return false;
    }

    /**
     * Parse product data from HTML content.
     */
    private function parseProductDataFromHtml(string $html, string $asin): array
    {
        try {
            $crawler = new Crawler($html);
            $productData = [];

            // Extract product title
            $productData['title'] = $this->extractProductTitle($crawler);
            
            // Extract main product image
            $productData['image_url'] = $this->extractProductImage($crawler);
            
            // Extract product description (optional, since it's already in the database)
            $productData['description'] = $this->extractProductDescription($crawler);

            LoggingService::log('Parsed product data from HTML', [
                'asin' => $asin,
                'title_found' => !empty($productData['title']),
                'image_found' => !empty($productData['image_url']),
                'description_found' => !empty($productData['description']),
            ]);

            return array_filter($productData); // Remove empty values

        } catch (\Exception $e) {
            LoggingService::log('Error parsing product data from HTML', [
                'asin' => $asin,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Extract product title from HTML.
     */
    private function extractProductTitle(Crawler $crawler): ?string
    {
        // First try meta tags (most reliable for social sharing)
        $metaSelectors = [
            'meta[name="title"]',
            'meta[property="og:title"]',
            'meta[name="twitter:title"]',
            'title',
        ];

        foreach ($metaSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $title = '';
                    if ($selector === 'title') {
                        $title = trim($element->text());
                    } else {
                        $title = trim($element->attr('content'));
                    }
                    
                    if (!empty($title)) {
                        // Clean up Amazon-specific prefixes/suffixes
                        $title = preg_replace('/^Amazon\.com:\s*/', '', $title);
                        $title = preg_replace('/\s*:\s*[^:]*&\s*[^:]*$/', '', $title); // Remove " : Category & Subcategory"
                        $title = trim($title);
                        
                        LoggingService::log('Found product title from meta', [
                            'selector' => $selector,
                            'title_length' => strlen($title),
                        ]);
                        return $title;
                    }
                }
            } catch (\Exception $e) {
                // Continue to next selector
                continue;
            }
        }

        // Fallback to traditional selectors
        $titleSelectors = [
            '#productTitle',
            '.product-title',
            '[data-automation-id="product-title"]',
            'h1.a-size-large',
            'h1.a-size-base-plus',
            'span[data-automation-id="product-title"]',
        ];

        foreach ($titleSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $title = trim($element->text());
                    if (!empty($title)) {
                        LoggingService::log('Found product title from DOM', [
                            'selector' => $selector,
                            'title_length' => strlen($title),
                        ]);
                        return $title;
                    }
                }
            } catch (\Exception $e) {
                // Continue to next selector
                continue;
            }
        }

        LoggingService::log('No product title found with any selector');
        return null;
    }

    /**
     * Extract main product image from HTML.
     */
    private function extractProductImage(Crawler $crawler): ?string
    {
        // First try meta tags (most reliable for social sharing)
        $metaImageSelectors = [
            'meta[property="og:image"]',
            'meta[name="twitter:image"]',
            'meta[property="og:image:url"]',
        ];

        foreach ($metaImageSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $imageUrl = trim($element->attr('content'));
                    if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                        LoggingService::log('Found product image from meta', [
                            'selector' => $selector,
                            'image_url' => $imageUrl,
                        ]);
                        return $imageUrl;
                    }
                }
            } catch (\Exception $e) {
                // Continue to next selector
                continue;
            }
        }

        // Try to extract from JSON data-a-state script (modern Amazon)
        try {
            $scriptElements = $crawler->filter('script[type="a-state"]');
            foreach ($scriptElements as $script) {
                $dataState = $script->getAttribute('data-a-state');
                if (!empty($dataState)) {
                    $decodedState = json_decode(html_entity_decode($dataState), true);
                    if (isset($decodedState['key']) && $decodedState['key'] === 'desktop-landing-image-data') {
                        $scriptContent = trim($script->textContent);
                        if (!empty($scriptContent)) {
                            $imageData = json_decode($scriptContent, true);
                            if (isset($imageData['landingImageUrl']) && filter_var($imageData['landingImageUrl'], FILTER_VALIDATE_URL)) {
                                LoggingService::log('Found product image from a-state data', [
                                    'image_url' => $imageData['landingImageUrl'],
                                ]);
                                return $imageData['landingImageUrl'];
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            LoggingService::log('Error extracting image from a-state data', ['error' => $e->getMessage()]);
        }

        // Fallback to traditional selectors
        $imageSelectors = [
            '#landingImage',
            '#imgBlkFront',
            '#main-image',
            '.a-dynamic-image',
            '[data-a-dynamic-image]',
            'img.a-dynamic-image',
            '#ebooksImgBlkFront',
        ];

        foreach ($imageSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    // Try to get the src attribute
                    $src = $element->attr('src');
                    if (!empty($src) && filter_var($src, FILTER_VALIDATE_URL)) {
                        LoggingService::log('Found product image', [
                            'selector' => $selector,
                            'image_url' => $src,
                        ]);
                        return $src;
                    }

                    // Try to get data-a-dynamic-image attribute
                    $dynamicImage = $element->attr('data-a-dynamic-image');
                    if (!empty($dynamicImage)) {
                        $imageData = json_decode($dynamicImage, true);
                        if (is_array($imageData) && !empty($imageData)) {
                            $imageUrl = array_key_first($imageData);
                            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                                LoggingService::log('Found product image from dynamic data', [
                                    'selector' => $selector,
                                    'image_url' => $imageUrl,
                                ]);
                                return $imageUrl;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continue to next selector
                continue;
            }
        }

        LoggingService::log('No product image found with any selector');
        return null;
    }

    /**
     * Extract product description from HTML.
     */
    private function extractProductDescription(Crawler $crawler): ?string
    {
        // First try meta tags (most reliable for social sharing)
        $metaDescriptionSelectors = [
            'meta[name="description"]',
            'meta[property="og:description"]',
            'meta[name="twitter:description"]',
        ];

        foreach ($metaDescriptionSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $description = trim($element->attr('content'));
                    if (!empty($description) && strlen($description) > 20) {
                        LoggingService::log('Found product description from meta', [
                            'selector' => $selector,
                            'description_length' => strlen($description),
                        ]);
                        return $description;
                    }
                }
            } catch (\Exception $e) {
                // Continue to next selector
                continue;
            }
        }

        // Fallback to traditional selectors
        $descriptionSelectors = [
            '#feature-bullets ul',
            '#aplus_feature_div',
            '#productDescription',
            '.a-unordered-list.a-vertical.a-spacing-mini',
            '[data-feature-name="featurebullets"]',
        ];

        foreach ($descriptionSelectors as $selector) {
            try {
                $element = $crawler->filter($selector);
                if ($element->count() > 0) {
                    $description = trim($element->text());
                    if (!empty($description) && strlen($description) > 20) {
                        LoggingService::log('Found product description from DOM', [
                            'selector' => $selector,
                            'description_length' => strlen($description),
                        ]);
                        return $description;
                    }
                }
            } catch (\Exception $e) {
                // Continue to next selector
                continue;
            }
        }

        LoggingService::log('No product description found with any selector');
        return null;
    }
} 