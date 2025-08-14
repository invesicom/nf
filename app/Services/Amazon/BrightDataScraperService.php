<?php

namespace App\Services\Amazon;

use App\Models\AsinData;
use App\Services\Amazon\AmazonReviewServiceInterface;
use App\Services\LoggingService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;

class BrightDataScraperService implements AmazonReviewServiceInterface
{
    private Client $httpClient;
    private string $apiKey;
    private string $datasetId;
    private string $baseUrl;
    private int $pollInterval;
    private int $maxAttempts;

    public function __construct(
        ?Client $httpClient = null,
        ?string $apiKey = null,
        ?string $datasetId = null,
        ?string $baseUrl = null,
        ?int $pollInterval = null,
        ?int $maxAttempts = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 1200, // 20 minutes - BrightData jobs can take a long time
            'connect_timeout' => 30,
            'http_errors' => false,
        ]);

        $this->apiKey = $apiKey ?? env('BRIGHTDATA_SCRAPER_API', '');
        $this->datasetId = $datasetId ?? env('BRIGHTDATA_DATASET_ID', 'gd_le8e811kzy4ggddlq');
        $this->baseUrl = $baseUrl ?? 'https://api.brightdata.com/datasets/v3';
        $this->pollInterval = $pollInterval ?? (app()->environment('testing') ? 0 : 30);
        $this->maxAttempts = $maxAttempts ?? (app()->environment('testing') ? 3 : 10);

        if (empty($this->apiKey)) {
            LoggingService::log('BrightData API key not configured', [
                'service' => 'BrightDataScraperService',
                'checked_vars' => ['BRIGHTDATA_SCRAPER_API']
            ]);
        }
    }

    /**
     * Set HTTP client for testing.
     */
    public function setHttpClient(Client $client): void
    {
        $this->httpClient = $client;
    }

    /**
     * Fetch reviews using BrightData's managed scraper API.
     */
    public function fetchReviews(string $asin, string $country = 'us'): array
    {
        if (empty($this->apiKey)) {
            LoggingService::log('BrightData API key missing, cannot fetch reviews', [
                'asin' => $asin,
                'service' => 'BrightDataScraperService'
            ]);
            return [
                'reviews' => [],
                'description' => '',
                'total_reviews' => 0
            ];
        }

        LoggingService::log('Starting BrightData scraping for ASIN', [
            'asin' => $asin,
            'country' => $country,
            'service' => 'BrightDataScraperService'
        ]);

        try {
            // Construct Amazon product URL
            $productUrl = $this->buildAmazonUrl($asin, $country);
            
            // Trigger BrightData scraping job
            $jobId = $this->triggerScrapingJob([$productUrl]);
            
            if (!$jobId) {
                LoggingService::log('Failed to trigger BrightData scraping job', [
                    'asin' => $asin,
                    'url' => $productUrl
                ]);
                return [
                    'reviews' => [],
                    'description' => '',
                    'total_reviews' => 0
                ];
            }

            // Poll for results
            $results = $this->pollForResults($jobId, $asin);
            
            if (empty($results)) {
                LoggingService::log('No results returned from BrightData', [
                    'asin' => $asin,
                    'job_id' => $jobId
                ]);
                return [
                    'reviews' => [],
                    'description' => '',
                    'total_reviews' => 0
                ];
            }

            // Transform BrightData format to our internal format
            $transformedData = $this->transformBrightDataResults($results, $asin);
            
            LoggingService::log('BrightData scraping completed successfully', [
                'asin' => $asin,
                'reviews_found' => count($transformedData['reviews']),
                'job_id' => $jobId
            ]);

            return $transformedData;

        } catch (\Exception $e) {
            LoggingService::log('BrightData scraping failed', [
                'asin' => $asin,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'reviews' => [],
                'description' => '',
                'total_reviews' => 0
            ];
        }
    }

    /**
     * Fetch reviews and save to database using job chain (async).
     */
    public function fetchReviewsAndSave(string $asin, string $country, string $productUrl): AsinData
    {
        // CRITICAL: If we're already running inside a queue job, always use sync mode
        // to avoid dispatching jobs from within jobs
        if (app()->runningInConsole() && 
            str_contains(implode(' ', $_SERVER['argv'] ?? []), 'queue:work')) {
            LoggingService::log('BrightData forced to sync mode - running inside queue worker', [
                'asin' => $asin,
                'country' => $country,
                'argv' => $_SERVER['argv'] ?? []
            ]);
            return $this->fetchReviewsSync($asin, $country);
        }
        
        // For async processing, dispatch the job chain
        $asyncEnabled = config('analysis.async_enabled') ?? 
                       filter_var(env('ANALYSIS_ASYNC_ENABLED'), FILTER_VALIDATE_BOOLEAN) ?? 
                       (env('APP_ENV') === 'production');
        
        if ($asyncEnabled) {
            return $this->fetchReviewsAsync($asin, $country);
        }
        
        // Synchronous fallback for testing or when async is disabled
        return $this->fetchReviewsSync($asin, $country);
    }

    /**
     * Fetch reviews asynchronously using job chain.
     */
    public function fetchReviewsAsync(string $asin, string $country): AsinData
    {
        LoggingService::log('Starting BrightData async job chain', [
            'asin' => $asin,
            'country' => $country
        ]);

        // Create or get existing AsinData record and set it to processing for async mode
        $asinData = AsinData::firstOrCreate(
            ['asin' => $asin, 'country' => $country],
            ['status' => 'processing']
        );
        
        // Always set to processing status for async mode
        if ($asinData->status !== 'processing') {
            $asinData->update(['status' => 'processing']);
        }

        // Dispatch the job chain
        \App\Jobs\TriggerBrightDataScraping::dispatch($asin, $country);

        return $asinData;
    }

    /**
     * Fetch reviews synchronously (for testing or fallback).
     */
    private function fetchReviewsSync(string $asin, string $country): AsinData
    {
        $result = $this->fetchReviews($asin, $country);
        
        // Save to database using existing columns only
        $asinData = AsinData::firstOrCreate(
            ['asin' => $asin, 'country' => $country],
            ['status' => 'pending_analysis']
        );
        
        $asinData->reviews = json_encode($result['reviews']);
        $asinData->product_description = $result['description'] ?? '';
        $asinData->total_reviews_on_amazon = $result['total_reviews'] ?? count($result['reviews']);
        $asinData->country = $country;
        $asinData->status = 'pending_analysis';
        
        // Extract product data if available from BrightData
        $hasProductTitle = false;
        $hasProductImage = false;
        
        if (!empty($result['product_name'])) {
            $asinData->product_title = $result['product_name'];
            $hasProductTitle = true;
        }
        if (!empty($result['product_image_url'])) {
            $asinData->product_image_url = $result['product_image_url'];
            $hasProductImage = true;
        }
        
        // Only set have_product_data = true if we actually have both title and image
        // If BrightData doesn't provide complete product metadata, let AmazonProductDataService handle it
        $asinData->have_product_data = $hasProductTitle && $hasProductImage;
        
        LoggingService::log('BrightData product data extraction results', [
            'asin' => $asin,
            'has_product_title' => $hasProductTitle,
            'has_product_image' => $hasProductImage,
            'have_product_data' => $asinData->have_product_data,
            'will_trigger_separate_scraping' => !$asinData->have_product_data
        ]);
        
        $asinData->save();

        return $asinData;
    }

    /**
     * Fetch product data using BrightData.
     */
    public function fetchProductData(string $asin, string $country = 'us'): array
    {
        $result = $this->fetchReviews($asin, $country);
        
        return [
            'title' => $result['product_name'] ?? '',
            'description' => $result['description'] ?? '',
            'price' => $result['price'] ?? '',
            'image_url' => $result['product_image_url'] ?? '',
            'rating' => 0, // Rating not available from BrightData review data
            'total_reviews' => $result['total_reviews'] ?? 0
        ];
    }

    /**
     * Build Amazon product URL for the given ASIN and country.
     */
    private function buildAmazonUrl(string $asin, string $country): string
    {
        $domains = [
            'us' => 'amazon.com',
            'gb' => 'amazon.co.uk',  // Fixed: use 'gb' to match ReviewService
            'uk' => 'amazon.co.uk',  // Keep 'uk' for backward compatibility
            'ca' => 'amazon.ca',
            'de' => 'amazon.de',
            'fr' => 'amazon.fr',
            'it' => 'amazon.it',
            'es' => 'amazon.es',
            'jp' => 'amazon.co.jp',
            'au' => 'amazon.com.au',
            // Additional countries for expanded support
            'mx' => 'amazon.com.mx',  // Mexico
            'in' => 'amazon.in',      // India
            'sg' => 'amazon.sg',      // Singapore
            'br' => 'amazon.com.br',  // Brazil
            // Already supported by ReviewService
            'nl' => 'amazon.nl',
            'tr' => 'amazon.com.tr',
            'ae' => 'amazon.ae',
            'sa' => 'amazon.sa',
            'se' => 'amazon.se',
            'pl' => 'amazon.pl',
            'eg' => 'amazon.eg',
            'be' => 'amazon.be'
        ];

        $domain = $domains[$country] ?? $domains['us'];
        return "https://www.{$domain}/dp/{$asin}/";
    }

    /**
     * Trigger a BrightData scraping job.
     */
    private function triggerScrapingJob(array $urls): ?string
    {
        try {
            $payload = array_map(function($url) {
                return ['url' => $url];
            }, $urls);

            LoggingService::log('Triggering BrightData scraping job', [
                'urls_count' => count($urls),
                'dataset_id' => $this->datasetId,
                'payload' => $payload
            ]);

            $response = $this->httpClient->post("{$this->baseUrl}/trigger", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'dataset_id' => $this->datasetId,
                    'include_errors' => 'true'  // Include errors as per API docs
                ],
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                LoggingService::log('BrightData job trigger failed', [
                    'status_code' => $statusCode,
                    'response_body' => $body
                ]);
                return null;
            }

            $data = json_decode($body, true);
            $jobId = $data['snapshot_id'] ?? null;

            LoggingService::log('BrightData job triggered successfully', [
                'job_id' => $jobId,
                'response' => $data
            ]);

            return $jobId;

        } catch (RequestException $e) {
            LoggingService::log('BrightData job trigger request failed', [
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);
            return null;
        }
    }

    /**
     * Poll BrightData for job results.
     */
    private function pollForResults(string $jobId, string $asin): array
    {
        $attempt = 0;

        LoggingService::log('Starting to poll for BrightData results', [
            'job_id' => $jobId,
            'asin' => $asin,
            'max_attempts' => $this->maxAttempts,
            'poll_interval' => $this->pollInterval
        ]);

        while ($attempt < $this->maxAttempts) {
            try {
                // Use the correct progress API endpoint to check job status
                $response = $this->httpClient->get("{$this->baseUrl}/progress/{$jobId}", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->apiKey}",
                    ]
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                if ($statusCode !== 200) {
                    LoggingService::log('BrightData progress check failed', [
                        'job_id' => $jobId,
                        'attempt' => $attempt + 1,
                        'status_code' => $statusCode,
                        'response_body' => substr($body, 0, 500)
                    ]);
                    
                    $attempt++;
                    if ($this->pollInterval > 0) {
                        sleep($this->pollInterval);
                    }
                    continue;
                }

                $progressData = json_decode($body, true);
                $status = $progressData['status'] ?? 'unknown';

                LoggingService::log('BrightData job progress check', [
                    'job_id' => $jobId,
                    'attempt' => $attempt + 1,
                    'status' => $status,
                    'total_rows' => $progressData['records'] ?? 0
                ]);

                if ($status === 'ready') {
                    // Job completed successfully, fetch the actual data
                    return $this->fetchJobData($jobId);
                } elseif ($status === 'failed' || $status === 'error') {
                    LoggingService::log('BrightData job failed', [
                        'job_id' => $jobId,
                        'status' => $status,
                        'progress_data' => $progressData
                    ]);
                    return [];
                } elseif ($status === 'running') {
                    LoggingService::log('BrightData job still running', [
                        'job_id' => $jobId,
                        'attempt' => $attempt + 1,
                        'max_attempts' => $this->maxAttempts,
                        'time_elapsed' => ($attempt * $this->pollInterval) . 's',
                        'estimated_remaining' => (($this->maxAttempts - $attempt) * $this->pollInterval) . 's'
                    ]);
                    
                    $attempt++;
                    if ($this->pollInterval > 0) {
                        sleep($this->pollInterval);
                    }
                    continue;
                }

                // Unknown status - continue polling
                $attempt++;
                sleep($pollInterval);

            } catch (RequestException $e) {
                LoggingService::log('BrightData polling request failed', [
                    'job_id' => $jobId,
                    'attempt' => $attempt + 1,
                    'error' => $e->getMessage()
                ]);
                
                $attempt++;
                sleep($pollInterval);
            }
        }

        // Get final status before giving up
        $finalProgressInfo = $this->getJobProgressInfo($jobId);
        
        LoggingService::log('BrightData polling timeout - job may still be running', [
            'job_id' => $jobId,
            'asin' => $asin,
            'max_attempts' => $this->maxAttempts,
            'total_time' => ($this->maxAttempts * $this->pollInterval) . 's',
            'final_status' => $finalProgressInfo['status'] ?? 'unknown',
            'final_rows' => $finalProgressInfo['total_rows'] ?? 0,
            'suggestion' => 'Job may complete later - check progress manually or increase timeout'
        ]);

        return [];
    }

    /**
     * Fetch the actual job data from BrightData.
     */
    private function fetchJobData(string $jobId): array
    {
        try {
            $response = $this->httpClient->get("{$this->baseUrl}/snapshot/{$jobId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ],
                'query' => [
                    'format' => 'json'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                LoggingService::log('BrightData data fetch failed', [
                    'job_id' => $jobId,
                    'status_code' => $statusCode,
                    'response_body' => substr($body, 0, 500)
                ]);
                return [];
            }

            $data = json_decode($body, true);

            LoggingService::log('BrightData data fetched successfully', [
                'job_id' => $jobId,
                'records_count' => is_array($data) ? count($data) : 0
            ]);

            return is_array($data) ? $data : [];

        } catch (RequestException $e) {
            LoggingService::log('BrightData data fetch request failed', [
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Transform BrightData results to our internal format.
     */
    private function transformBrightDataResults(array $results, string $asin): array
    {
        $reviews = [];
        $productName = '';
        $productRating = 0;
        $totalReviews = 0;
        $productImageUrl = '';
        $description = '';

        foreach ($results as $item) {
            // Extract product-level data from first item
            if (empty($productName) && !empty($item['product_name'])) {
                $productName = $item['product_name'];
                $totalReviews = $item['product_rating_count'] ?? 0;
                
                // Extract product image URL if provided by BrightData
                if (empty($productImageUrl) && !empty($item['product_image_url'])) {
                    $productImageUrl = $item['product_image_url'];
                }
            }

            // Transform review data - BrightData provides very rich data
            if (!empty($item['review_text']) && !empty($item['review_id'])) {
                $review = [
                    'id' => $item['review_id'],
                    'rating' => $item['rating'] ?? 0,
                    'title' => $item['review_header'] ?? '',
                    'review_text' => $item['review_text'],
                    'author' => $item['author_name'] ?? 'Anonymous',
                    'date' => $item['review_posted_date'] ?? '',
                    'verified_purchase' => $item['is_verified'] ?? false,
                    'helpful_count' => $item['helpful_count'] ?? 0,
                    'vine_review' => $item['is_amazon_vine'] ?? false,
                    'country' => $item['review_country'] ?? '',
                    'badge' => $item['badge'] ?? '',
                    'author_id' => $item['author_id'] ?? '',
                    'author_link' => $item['author_link'] ?? '',
                    'variant_asin' => $item['variant_asin'] ?? null,
                    'variant_name' => $item['variant_name'] ?? null,
                    'brand' => $item['brand'] ?? '',
                    'timestamp' => $item['timestamp'] ?? '',
                ];

                // Add image URLs if available
                if (!empty($item['review_images']) && is_array($item['review_images'])) {
                    $review['images'] = $item['review_images'];
                }

                // Add video URLs if available  
                if (!empty($item['videos']) && is_array($item['videos'])) {
                    $review['videos'] = $item['videos'];
                }

                $reviews[] = $review;
            }
        }

        LoggingService::log('BrightData results transformed', [
            'asin' => $asin,
            'total_items' => count($results),
            'reviews_extracted' => count($reviews),
            'product_name' => $productName,
            'total_reviews_on_amazon' => $totalReviews
        ]);

        return [
            'reviews' => $reviews,
            'description' => $description,
            'total_reviews' => $totalReviews,
            'product_name' => $productName,
            'product_image_url' => $productImageUrl
        ];
    }

    /**
     * Check BrightData progress for all jobs.
     */
    public function checkProgress(): array
    {
        try {
            $response = $this->httpClient->get("{$this->baseUrl}/progress/", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                LoggingService::log('BrightData progress check failed', [
                    'status_code' => $statusCode,
                    'response_body' => substr($body, 0, 500)
                ]);
                return [];
            }

            $data = json_decode($body, true);
            
            LoggingService::log('BrightData progress check successful', [
                'active_jobs' => is_array($data) ? count($data) : 0
            ]);

            return is_array($data) ? $data : [];

        } catch (\Exception $e) {
            LoggingService::log('BrightData progress check error', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get detailed progress information for a specific job.
     */
    private function getJobProgressInfo(string $jobId): array
    {
        try {
            $response = $this->httpClient->get("{$this->baseUrl}/progress/{$jobId}", [
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $progressData = json_decode($response->getBody()->getContents(), true);
                
                // Response is directly for the specific job
                if (is_array($progressData)) {
                    return [
                        'status' => $progressData['status'] ?? 'unknown',
                        'total_rows' => $progressData['records'] ?? 0,
                        'created_at' => $progressData['created_at'] ?? null,
                        'updated_at' => $progressData['updated_at'] ?? null
                    ];
                }
            }
        } catch (\Exception $e) {
            // Don't log progress API errors as they're supplemental
        }

        return ['status' => 'unknown', 'total_rows' => 0];
    }

}
