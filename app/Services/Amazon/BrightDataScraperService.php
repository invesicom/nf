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

    public function __construct()
    {
        $this->httpClient = new Client([
            'timeout' => 1200, // 20 minutes - BrightData jobs can take a long time
            'connect_timeout' => 30,
            'http_errors' => false,
        ]);

        $this->apiKey = env('BRIGHTDATA_SCRAPER_API');
        $this->datasetId = env('BRIGHTDATA_DATASET_ID', 'gd_le8e811kzy4ggddlq');
        $this->baseUrl = 'https://api.brightdata.com/datasets/v3';

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
     * Fetch reviews and save to database.
     */
    public function fetchReviewsAndSave(string $asin, string $country, string $productUrl): AsinData
    {
        $result = $this->fetchReviews($asin, $country);
        
        // Save to database
        $asinData = AsinData::firstOrCreate(['asin' => $asin]);
        $asinData->reviews = json_encode($result['reviews']);
        $asinData->product_description = $result['description'];
        $asinData->total_reviews_on_amazon = $result['total_reviews'];
        
        // Extract additional product data if available
        if (!empty($result['product_name'])) {
            $asinData->product_title = $result['product_name'];
        }
        if (!empty($result['product_rating'])) {
            $asinData->product_rating = $result['product_rating'];
        }
        if (!empty($result['product_image_url'])) {
            $asinData->product_image_url = $result['product_image_url'];
        }
        
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
            'rating' => $result['product_rating'] ?? 0,
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
            'uk' => 'amazon.co.uk',
            'ca' => 'amazon.ca',
            'de' => 'amazon.de',
            'fr' => 'amazon.fr',
            'it' => 'amazon.it',
            'es' => 'amazon.es',
            'jp' => 'amazon.co.jp',
            'au' => 'amazon.com.au'
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
        $maxAttempts = 20; // 10 minutes with 30-second intervals  
        $attempt = 0;
        $pollInterval = 30; // seconds - BrightData recommends 30s intervals

        LoggingService::log('Starting to poll for BrightData results', [
            'job_id' => $jobId,
            'asin' => $asin,
            'max_attempts' => $maxAttempts,
            'poll_interval' => $pollInterval
        ]);

        while ($attempt < $maxAttempts) {
            try {
                $response = $this->httpClient->get("{$this->baseUrl}/snapshot/{$jobId}", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->apiKey}",
                    ]
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                if ($statusCode === 202) {
                    // 202 means job is still running, this is expected
                    $responseData = json_decode($body, true);
                    
                    // Get more detailed status from progress API
                    $progressInfo = $this->getJobProgressInfo($jobId);
                    
                    LoggingService::log('BrightData job still running', [
                        'job_id' => $jobId,
                        'attempt' => $attempt + 1,
                        'max_attempts' => $maxAttempts,
                        'time_elapsed' => ($attempt * $pollInterval) . 's',
                        'estimated_remaining' => (($maxAttempts - $attempt) * $pollInterval) . 's',
                        'status' => $responseData['status'] ?? 'running',
                        'message' => $responseData['message'] ?? 'Processing...',
                        'progress_info' => $progressInfo
                    ]);
                    
                    $attempt++;
                    sleep($pollInterval);
                    continue;
                } elseif ($statusCode !== 200) {
                    LoggingService::log('BrightData polling failed', [
                        'job_id' => $jobId,
                        'attempt' => $attempt + 1,
                        'status_code' => $statusCode,
                        'response_body' => substr($body, 0, 500)
                    ]);
                    
                    $attempt++;
                    sleep($pollInterval);
                    continue;
                }

                $data = json_decode($body, true);
                $status = $data['status'] ?? 'unknown';

                LoggingService::log('BrightData job status check', [
                    'job_id' => $jobId,
                    'attempt' => $attempt + 1,
                    'status' => $status,
                    'total_rows' => $data['total_rows'] ?? 0
                ]);

                if ($status === 'ready') {
                    // Job completed, fetch the actual data
                    return $this->fetchJobData($jobId);
                } elseif ($status === 'failed' || $status === 'error') {
                    LoggingService::log('BrightData job failed', [
                        'job_id' => $jobId,
                        'status' => $status,
                        'data' => $data
                    ]);
                    return [];
                } elseif ($status === 'unknown' && $attempt >= 3) {
                    // BrightData API sometimes returns 'unknown' status even when data is ready
                    // After 3 attempts (1.5 minutes), try to fetch data anyway
                    LoggingService::log('Attempting to fetch BrightData data despite unknown status', [
                        'job_id' => $jobId,
                        'attempt' => $attempt + 1,
                        'reason' => 'Status API shows unknown but data might be ready'
                    ]);
                    
                    $dataResult = $this->fetchJobData($jobId);
                    if (!empty($dataResult)) {
                        LoggingService::log('Successfully retrieved data despite unknown status', [
                            'job_id' => $jobId,
                            'records_found' => count($dataResult)
                        ]);
                        return $dataResult;
                    }
                }

                // Job still running or unknown, continue polling
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
            'max_attempts' => $maxAttempts,
            'total_time' => ($maxAttempts * $pollInterval) . 's',
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
                $productRating = $item['product_rating'] ?? 0;
                $totalReviews = $item['product_rating_count'] ?? 0;
                // BrightData doesn't provide product image or description in review data
                // These would need to be fetched separately if needed
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
            'product_rating' => $productRating,
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
