<?php

namespace App\Services\Amazon;

use App\Models\AsinData;
use App\Services\AlertService;
use App\Services\LoggingService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;

/**
 * Service for fetching Amazon product reviews via Unwrangle API.
 */
class AmazonFetchService
{
    private Client $httpClient;

    /**
     * Initialize the service with HTTP client configuration.
     */
    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout'     => 20,
            'http_errors' => false,
            'connect_timeout' => 5,
        ]);
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
        // Fetch reviews from Amazon with optimized performance
        $reviewsData = $this->fetchReviewsOptimized($asin, $country);

        // Check if fetching failed and provide specific error message
        if (empty($reviewsData) || !isset($reviewsData['reviews'])) {
            // Provide more specific error messages based on common failure scenarios
            throw new \Exception('Unable to fetch product reviews at this time. This could be due to:
• The product URL being invalid or the product not existing on Amazon
• Amazon blocking our review service (temporary)
• Network connectivity issues
• The review service being overloaded

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
     * Optimized version of fetchReviews with improved performance
     */
    public function fetchReviewsOptimized(string $asin, string $country = 'us'): array
    {
        // Only do basic ASIN format validation - let client-side JS and Unwrangle API handle the rest
        if (!$this->isValidAsinFormat($asin)) {
            LoggingService::log('ASIN format validation failed', [
                'asin' => $asin,
            ]);
            return [];
        }
        
        LoggingService::log('Server-side validation skipped - using client-side validation', [
            'asin' => $asin,
        ]);

        $apiKey = env('UNWRANGLE_API_KEY');
        $cookie = env('UNWRANGLE_AMAZON_COOKIE');
        $baseUrl = 'https://data.unwrangle.com/api/getter/';
        $maxPages = 10; // Keep original page count for comprehensive data
        $country = 'us';

        $query = [
            'platform'     => 'amazon_reviews',
            'asin'         => $asin,
            'country_code' => $country,
            'max_pages'    => $maxPages,
            'api_key'      => $apiKey,
            'cookie'       => $cookie,
        ];

        // Try with shorter timeout first, then fallback with fewer pages
        $attempts = [
            ['timeout' => 30, 'max_pages' => 5, 'description' => 'quick fetch (5 pages)'],
            ['timeout' => 45, 'max_pages' => 10, 'description' => 'full fetch (10 pages)'],
        ];

        foreach ($attempts as $attemptIndex => $attempt) {
            try {
                LoggingService::log("Attempt ".($attemptIndex + 1).": {$attempt['description']}", [
                    'asin' => $asin,
                    'timeout' => $attempt['timeout'],
                    'max_pages' => $attempt['max_pages']
                ]);

                $query['max_pages'] = $attempt['max_pages'];
                $startTime = microtime(true);
                
                $response = $this->httpClient->request('GET', $baseUrl, [
                    'query' => $query,
                    'timeout' => $attempt['timeout'],
                    'connect_timeout' => 5,
                ]);
                
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000, 2);
                
                $status = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                LoggingService::log('Unwrangle API response', [
                    'attempt' => $attemptIndex + 1,
                    'status'   => $status,
                    'has_data' => !empty($body),
                    'duration_ms' => $duration,
                    'max_pages' => $attempt['max_pages']
                ]);

                if ($status !== 200) {
                    LoggingService::log('Unwrangle API non-200 response', [
                        'attempt' => $attemptIndex + 1,
                        'status' => $status,
                        'body'   => substr($body, 0, 500), // Limit log size
                    ]);

                    // Check for Amazon session expired error
                    $data = json_decode($body, true);
                    if ($data && isset($data['error_code']) && $data['error_code'] === 'AMAZON_SIGNIN_REQUIRED') {
                        app(AlertService::class)->amazonSessionExpired(
                            $data['message'] ?? 'Amazon session has expired',
                            [
                                'asin' => $asin,
                                'status_code' => $status,
                                'error_code' => $data['error_code'],
                            ]
                        );
                    }

                    // Try next attempt if available
                    continue;
                }

                $data = json_decode($body, true);

                if (empty($data) || empty($data['success'])) {
                    LoggingService::log('Unwrangle API returned error', [
                        'attempt' => $attemptIndex + 1,
                        'error' => $data['error'] ?? 'Unknown error',
                    ]);

                    // Try next attempt if available
                    continue;
                }

                $totalReviews = $data['total_results'] ?? count($data['reviews'] ?? []);
                $description = $data['description'] ?? '';
                $allReviews = $data['reviews'] ?? [];

                // Success! Log and return results
                LoggingService::log("Successfully retrieved ".count($allReviews)." reviews for analysis (attempt ".($attemptIndex + 1).")");

                return [
                    'reviews'       => $allReviews,
                    'description'   => $description,
                    'total_reviews' => $totalReviews,
                ];

            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                LoggingService::log('Unwrangle API request exception', [
                    'attempt' => $attemptIndex + 1,
                    'error' => $errorMessage,
                    'asin'  => $asin,
                ]);

                // Check if this is a timeout error
                if (str_contains($errorMessage, 'cURL error 28') || str_contains($errorMessage, 'timed out')) {
                    LoggingService::log("Timeout occurred on attempt ".($attemptIndex + 1).", trying next attempt if available");
                    
                    // Continue to next attempt if timeout
                    continue;
                }

                // For non-timeout errors, break and return empty result
                LoggingService::log("Non-timeout error on attempt ".($attemptIndex + 1).", stopping attempts: {$errorMessage}");
                break;
            }
        }

        // If we get here, all attempts failed
        LoggingService::log('All Unwrangle API attempts failed', ['asin' => $asin]);
        return [];
    }

    /**
     * Fetch reviews from Amazon using Unwrangle API.
     *
     * @param string $asin    Amazon Standard Identification Number
     * @param string $country Two-letter country code (defaults to 'us')
     *
     * @return array<string, mixed> Array containing reviews, description, and total count
     */
    public function fetchReviews(string $asin, string $country = 'us'): array
    {
        // Use optimized version
        return $this->fetchReviewsOptimized($asin, $country);
    }

    /**
     * Validate ASIN format without hitting Amazon servers
     */
    private function isValidAsinFormat(string $asin): bool
    {
        // ASIN should be exactly 10 characters, alphanumeric
        return preg_match('/^[A-Z0-9]{10}$/', $asin) === 1;
    }
}
