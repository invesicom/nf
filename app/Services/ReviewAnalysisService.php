<?php

namespace App\Services;

use App\Models\AsinData;
use App\Services\Amazon\AmazonReviewServiceInterface;
use App\Services\Amazon\AmazonReviewServiceFactory;

class ReviewAnalysisService
{
    private AmazonReviewServiceInterface $fetchService;
    private ReviewService $reviewService;
    private OpenAIService $openAIService;

    public function __construct(
        ReviewService $reviewService,
        OpenAIService $openAIService
    ) {
        $this->fetchService = AmazonReviewServiceFactory::create();
        $this->reviewService = $reviewService;
        $this->openAIService = $openAIService;
    }

    private function extractAsinFromUrl($url): string
    {
        // Handle short URLs (a.co redirects)
        if (preg_match('/^https?:\/\/a\.co\//', $url)) {
            $url = $this->followRedirect($url);
            LoggingService::log("Followed redirect to: {$url}");
        }

        // Extract ASIN from various Amazon URL patterns
        $patterns = [
            '/\/dp\/([A-Z0-9]{10})/',           // /dp/ASIN
            '/\/product\/([A-Z0-9]{10})/',      // /product/ASIN
            '/\/product-reviews\/([A-Z0-9]{10})/', // /product-reviews/ASIN
            '/\/gp\/product\/([A-Z0-9]{10})/',  // /gp/product/ASIN
            '/ASIN=([A-Z0-9]{10})/',            // ASIN=ASIN parameter
            '/\/([A-Z0-9]{10})(?:\/|\?|$)/',    // ASIN in path
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                LoggingService::log("Extracted ASIN '{$matches[1]}' using pattern: {$pattern}");

                return $matches[1];
            }
        }

        // If it's already just an ASIN
        if (preg_match('/^[A-Z0-9]{10}$/', $url)) {
            return $url;
        }

        throw new \Exception('Could not extract ASIN from URL: '.$url);
    }

    private function followRedirect(string $url): string
    {
        // Only follow redirects for trusted domains
        if (!preg_match('/^https?:\/\/a\.co\//', $url)) {
            throw new \Exception('Redirect following only allowed for a.co domains');
        }

        try {
            LoggingService::log("Following redirect for URL: {$url}");

            // Track redirects manually using on_redirect callback
            $redirectUrls = [];
            $client = new \GuzzleHttp\Client([
                'timeout'         => 10,
                'allow_redirects' => [
                    'max'         => 5,
                    'strict'      => true,
                    'referer'     => true,
                    'on_redirect' => function ($request, $response, $uri) use (&$redirectUrls) {
                        $redirectUrls[] = (string) $uri;
                    },
                ],
                'headers' => [
                    'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                    'Accept'                    => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language'           => 'en-US,en;q=0.5',
                    'Accept-Encoding'           => 'gzip, deflate',
                    'Connection'                => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ],
            ]);

            $response = $client->get($url);
            $finalUrl = !empty($redirectUrls) ? end($redirectUrls) : $url;

            LoggingService::log('Redirect resolved', [
                'original_url'   => $url,
                'final_url'      => $finalUrl,
                'status_code'    => $response->getStatusCode(),
                'redirect_chain' => $redirectUrls,
            ]);

            // Verify the redirect goes to Amazon
            if (preg_match('/^https?:\/\/(?:www\.)?amazon\.(com|co\.uk|ca|com\.au|de|fr|it|es|in|co\.jp|com\.mx|com\.br|nl|sg|com\.tr|ae|sa|se|pl|eg|be)/', $finalUrl)) {
                return $finalUrl;
            } else {
                throw new \Exception('Redirect does not lead to Amazon domain: '.$finalUrl);
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            LoggingService::log('Redirect following failed: '.$e->getMessage());

            throw new \Exception('Failed to follow redirect: '.$e->getMessage());
        } catch (\Exception $e) {
            LoggingService::log('Redirect following failed: '.$e->getMessage());

            throw new \Exception('Failed to follow redirect: '.$e->getMessage());
        }
    }

    public function analyzeProduct(string $asin, string $country = 'us'): array
    {
        LoggingService::log('=== ANALYZE METHOD STARTED ===');

        try {
            // Handle both URL string and direct ASIN inputs
            if (is_string($asin)) {
                $asin = $this->extractAsinFromUrl($asin);
                $country = 'us'; // Default country
                $productUrl = "https://www.amazon.com/dp/{$asin}";

                // Step 1: Check if analysis already exists in database
                $asinData = AsinData::where('asin', $asin)->where('country', $country)->first();

                if (!$asinData) {
                    LoggingService::log('Product not found in database, starting fetch process for ASIN: '.$asin);
                    LoggingService::log('Progress: 1/4 - Gathering Amazon reviews...');

                    // Step 2: Fetch reviews from Amazon
                    $asinData = $this->fetchService->fetchReviewsAndSave($asin, $country, $productUrl);

                    if (!$asinData) {
                        throw new \Exception("This product (ASIN: {$asin}) does not exist on Amazon US. Please verify the product URL and ensure it's available on amazon.com.");
                    }

                    LoggingService::log('Gathered '.count($asinData->getReviewsArray()).' reviews, now sending to OpenAI for analysis');

                    // Step 4: Analyze with OpenAI and update the same record
                    $openaiResult = $this->openAIService->analyzeReviews($asinData->getReviewsArray());

                    // Step 5: Update the record with OpenAI analysis
                    $asinData->update([
                        'openai_result' => json_encode($openaiResult),
                        'status'        => 'completed',
                    ]);

                    LoggingService::log('Updated database record with OpenAI analysis results');
                } else {
                    LoggingService::log("Found existing analysis in database for ASIN: {$asin}");

                    // Check if OpenAI analysis is missing and complete it if needed
                    if (!$asinData->openai_result) {
                        LoggingService::log("OpenAI analysis missing, completing analysis for ASIN: {$asin}");

                        $reviews = $asinData->getReviewsArray();
                        if (empty($reviews)) {
                            throw new \Exception('No reviews found in database for analysis');
                        }

                        LoggingService::logProgress('Completing analysis', 'Analyzing reviews with OpenAI...');
                        $openaiResult = $this->openAIService->analyzeReviews($reviews);

                        $asinData->update([
                            'openai_result' => json_encode($openaiResult),
                            'status'        => 'completed',
                        ]);

                        LoggingService::log("Completed missing OpenAI analysis for ASIN: {$asin}");

                        // Refresh the model to get updated data
                        $asinData = $asinData->fresh();
                    }
                }
            } else {
                // Input is already an AsinData object
                $asinData = $asin;
            }

            // Step 6: Calculate final metrics from the data
            if (!$asinData->openai_result) {
                throw new \Exception('No OpenAI analysis data available for this product');
            }

            // Calculate metrics directly from the data
            $openaiResult = $asinData->openai_result;
            if (is_string($openaiResult)) {
                $openaiResult = json_decode($openaiResult, true);
            }

            $detailedScores = $openaiResult['detailed_scores'] ?? [];
            $reviews = $asinData->getReviewsArray();

            $totalReviews = count($reviews);
            $fakeCount = 0;
            $amazonRatingSum = 0;
            $genuineRatingSum = 0;
            $genuineCount = 0;

            LoggingService::log('=== STARTING CALCULATION DEBUG ===');
            LoggingService::log("Total reviews found: {$totalReviews}");
            LoggingService::log('Detailed scores count: '.count($detailedScores));

            $fakeReviews = [];
            $genuineReviews = [];

            foreach ($reviews as $review) {
                $reviewId = $review['id'];
                $rating = $review['rating'];
                $amazonRatingSum += $rating;

                $fakeScore = $detailedScores[$reviewId] ?? 0;

                if ($fakeScore >= 70) {
                    $fakeCount++;
                    $fakeReviews[] = [
                        'id'         => $reviewId,
                        'rating'     => $rating,
                        'fake_score' => $fakeScore,
                    ];
                    LoggingService::log("FAKE REVIEW: ID={$reviewId}, Rating={$rating}, Score={$fakeScore}");
                } else {
                    $genuineRatingSum += $rating;
                    $genuineCount++;
                    $genuineReviews[] = [
                        'id'         => $reviewId,
                        'rating'     => $rating,
                        'fake_score' => $fakeScore,
                    ];
                }
            }

            LoggingService::log('=== FAKE REVIEWS SUMMARY ===');
            LoggingService::log("Total fake reviews: {$fakeCount}");
            foreach ($fakeReviews as $fake) {
                LoggingService::log("Fake: {$fake['id']} - Rating: {$fake['rating']} - Score: {$fake['fake_score']}");
            }

            LoggingService::log('=== GENUINE REVIEWS SUMMARY ===');
            LoggingService::log("Total genuine reviews: {$genuineCount}");
            $genuineRatingCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            foreach ($genuineReviews as $genuine) {
                $genuineRatingCounts[$genuine['rating']]++;
            }
            foreach ($genuineRatingCounts as $rating => $count) {
                LoggingService::log("Genuine {$rating}-star reviews: {$count}");
            }

            $fakePercentage = $totalReviews > 0 ? ($fakeCount / $totalReviews) * 100 : 0;
            $amazonRating = $totalReviews > 0 ? $amazonRatingSum / $totalReviews : 0;
            $adjustedRating = $this->calculateAdjustedRating($genuineReviews);

            LoggingService::log('=== FINAL CALCULATIONS ===');
            LoggingService::log("Amazon rating sum: {$amazonRatingSum}");
            LoggingService::log("Amazon rating average: {$amazonRating}");
            LoggingService::log("Genuine rating sum: {$genuineRatingSum}");
            LoggingService::log("Genuine rating average: {$adjustedRating}");
            LoggingService::log("Fake percentage: {$fakePercentage}%");

            $grade = $this->calculateGrade($fakePercentage);
            $explanation = $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);

            // Update the database with calculated values
            $asinData->update([
                'fake_percentage' => round($fakePercentage, 1),
                'amazon_rating'   => round($amazonRating, 2),
                'adjusted_rating' => round($adjustedRating, 2),
                'grade'           => $grade,
                'explanation'     => $explanation,
            ]);

            return [
                'fake_percentage' => round($fakePercentage, 1),
                'amazon_rating'   => round($amazonRating, 2),
                'adjusted_rating' => round($adjustedRating, 2),
                'grade'           => $grade,
                'explanation'     => $explanation,
                'total_reviews'   => $totalReviews,
                'asin_review'     => $asinData->fresh(),
            ];
        } catch (\Exception $e) {
            $userMessage = LoggingService::handleException($e);

            throw new \Exception($userMessage);
        }
    }

    private function calculateGrade($fakePercentage): string
    {
        if ($fakePercentage <= 10) {
            return 'A';
        } elseif ($fakePercentage <= 20) {
            return 'B';
        } elseif ($fakePercentage <= 35) {
            return 'C';
        } elseif ($fakePercentage <= 50) {
            return 'D';
        } else {
            return 'F';
        }
    }

    private function generateExplanation($totalReviews, $fakeCount, $fakePercentage): string
    {
        return "Analysis of {$totalReviews} reviews found {$fakeCount} potentially fake reviews (".round($fakePercentage, 1).'%). '.
               ($fakePercentage <= 10 ? 'This product has very low fake review activity and appears highly trustworthy.' :
               ($fakePercentage <= 20 ? 'This product has low fake review activity and appears trustworthy.' :
               ($fakePercentage <= 35 ? 'This product has moderate fake review activity. Exercise some caution.' :
               ($fakePercentage <= 50 ? 'This product has high fake review activity. Exercise caution when purchasing.' :
                'This product has very high fake review activity. We recommend avoiding this product.'))));
    }

    /**
     * Calculate adjusted rating based on genuine reviews only.
     */
    private function calculateAdjustedRating(array $genuineReviews): float
    {
        if (empty($genuineReviews)) {
            return 0;
        }

        $totalRating = array_sum(array_column($genuineReviews, 'rating'));
        $adjustedRating = $totalRating / count($genuineReviews);

        return round($adjustedRating, 2);
    }

    /**
     * Phase 1: Extract ASIN and check if product exists in database.
     */
    public function checkProductExists(string $productUrl): array
    {
        $asin = $this->extractAsinFromUrl($productUrl);
        $country = 'us';
        $productUrl = "https://www.amazon.com/dp/{$asin}";

        $asinData = AsinData::where('asin', $asin)->where('country', $country)->first();

        return [
            'asin'           => $asin,
            'country'        => $country,
            'product_url'    => $productUrl,
            'exists'         => $asinData !== null,
            'asin_data'      => $asinData,
            'needs_fetching' => $asinData === null,
            'needs_openai'   => $asinData === null || !$asinData->openai_result,
        ];
    }

    /**
     * Phase 2: Fetch reviews from Amazon (if needed).
     */
    public function fetchReviews(string $asin, string $country, string $productUrl): AsinData
    {
        LoggingService::log("Starting scrape process for ASIN: {$asin}");

        $asinData = $this->fetchService->fetchReviewsAndSave($asin, $country, $productUrl);

        if (!$asinData) {
            throw new \Exception("This product (ASIN: {$asin}) does not exist on Amazon US. Please verify the product URL and ensure it's available on amazon.com.");
        }

        LoggingService::log('Gathered '.count($asinData->getReviewsArray()).' reviews');

        // Queue product data scraping job if not already scraped
        if (!$asinData->have_product_data) {
            LoggingService::log('Queuing product data scraping job', [
                'asin' => $asin,
                'asin_data_id' => $asinData->id,
            ]);
            
            \App\Jobs\ScrapeAmazonProductData::dispatch($asinData);
        }

        return $asinData;
    }

    /**
     * Phase 3: Analyze reviews with OpenAI (if needed).
     */
    public function analyzeWithOpenAI(AsinData $asinData): AsinData
    {
        $reviews = $asinData->getReviewsArray();

        if (empty($reviews)) {
            throw new \Exception('No reviews found for analysis');
        }

        LoggingService::log('Sending '.count($reviews).' reviews to OpenAI for analysis');

        try {
            $openaiResult = $this->openAIService->analyzeReviews($reviews);
            
            $asinData->update([
                'openai_result' => json_encode($openaiResult),
                'status'        => 'completed',
            ]);

            LoggingService::log('Updated database record with OpenAI analysis results');

        } catch (\Exception $e) {
            LoggingService::log('OpenAI analysis failed, using fallback analysis', ['error' => $e->getMessage()]);
            
            // Check if it's a quota/billing issue
            if (str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), '429')) {
                LoggingService::log('OpenAI quota exceeded, applying heuristic fallback analysis');
                $fallbackResult = $this->generateFallbackAnalysis($reviews);
                
                $asinData->update([
                    'openai_result' => json_encode($fallbackResult),
                    'status'        => 'completed_fallback',
                    'analysis_notes' => 'Analysis completed using heuristic fallback due to OpenAI quota limits'
                ]);

                LoggingService::log('Updated database record with fallback analysis results');
            } else {
                // Re-throw other types of errors
                throw $e;
            }
        }

        return $asinData->fresh();
    }

    /**
     * Phase 4: Calculate final metrics and grades.
     */
    public function calculateFinalMetrics(AsinData $asinData): array
    {
        if (!$asinData->openai_result) {
            throw new \Exception('No OpenAI analysis data available for this product');
        }

        // Calculate metrics directly from the data
        $openaiResult = $asinData->openai_result;
        if (is_string($openaiResult)) {
            $openaiResult = json_decode($openaiResult, true);
        }

        $detailedScores = $openaiResult['detailed_scores'] ?? [];
        $reviews = $asinData->getReviewsArray();

        $totalReviews = count($reviews);
        $fakeCount = 0;
        $amazonRatingSum = 0;
        $genuineReviews = [];

        LoggingService::log('=== STARTING CALCULATION DEBUG ===');
        LoggingService::log("Total reviews found: {$totalReviews}");
        LoggingService::log('Detailed scores count: '.count($detailedScores));

        // Optimized single-pass calculation
        foreach ($reviews as $review) {
            $reviewId = $review['id'];
            $rating = $review['rating'];
            $amazonRatingSum += $rating;

            $fakeScore = $detailedScores[$reviewId] ?? 0;

            if ($fakeScore >= 70) {
                $fakeCount++;
                if ($fakeCount <= 8) { // Only log first 8 fake reviews to reduce log spam
                    LoggingService::log("FAKE REVIEW: ID={$reviewId}, Rating={$rating}, Score={$fakeScore}");
                }
            } else {
                $genuineReviews[] = [
                    'id'         => $reviewId,
                    'rating'     => $rating,
                    'fake_score' => $fakeScore,
                ];
            }
        }

        LoggingService::log('=== FAKE REVIEWS SUMMARY ===');
        LoggingService::log("Total fake reviews: {$fakeCount}");

        LoggingService::log('=== GENUINE REVIEWS SUMMARY ===');
        LoggingService::log("Total genuine reviews: ".count($genuineReviews));
        
        // Optimized rating count calculation
        $genuineRatingCounts = array_count_values(array_column($genuineReviews, 'rating'));
        for ($rating = 1; $rating <= 5; $rating++) {
            $count = $genuineRatingCounts[$rating] ?? 0;
            LoggingService::log("Genuine {$rating}-star reviews: {$count}");
        }

        $fakePercentage = $totalReviews > 0 ? ($fakeCount / $totalReviews) * 100 : 0;
        $amazonRating = $totalReviews > 0 ? $amazonRatingSum / $totalReviews : 0;
        $adjustedRating = $this->calculateAdjustedRating($genuineReviews);

        LoggingService::log('=== FINAL CALCULATIONS ===');
        LoggingService::log("Amazon rating sum: {$amazonRatingSum}");
        LoggingService::log("Amazon rating average: {$amazonRating}");
        LoggingService::log("Genuine rating average: {$adjustedRating}");
        LoggingService::log("Fake percentage: {$fakePercentage}%");

        $grade = $this->calculateGrade($fakePercentage);
        $explanation = $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);

        // Update the database with calculated values
        $asinData->update([
            'fake_percentage' => round($fakePercentage, 1),
            'amazon_rating'   => round($amazonRating, 2),
            'adjusted_rating' => round($adjustedRating, 2),
            'grade'           => $grade,
            'explanation'     => $explanation,
        ]);

        return [
            'fake_percentage' => round($fakePercentage, 1),
            'amazon_rating'   => round($amazonRating, 2),
            'adjusted_rating' => round($adjustedRating, 2),
            'grade'           => $grade,
            'explanation'     => $explanation,
            'total_reviews'   => $totalReviews,
            'asin_review'     => $asinData->fresh(),
        ];
    }

    /**
     * Generate a basic heuristic analysis when OpenAI is unavailable
     */
    private function generateFallbackAnalysis(array $reviews): array
    {
        LoggingService::log('Generating heuristic fallback analysis for '.count($reviews).' reviews');
        
        $results = [];
        
        foreach ($reviews as $review) {
            $score = $this->calculateHeuristicFakeScore($review);
            $results[] = [
                'id' => $review['id'] ?? uniqid(),
                'score' => $score
            ];
        }
        
        $summary = $this->generateFallbackSummary($reviews, $results);
        
        return [
            'detailed_scores' => array_column($results, 'score', 'id'),
            'results' => $results,
            'summary' => $summary,
            'analysis_type' => 'heuristic_fallback',
            'fallback_reason' => 'OpenAI quota exceeded'
        ];
    }

    /**
     * Calculate a heuristic fake review score based on common patterns
     */
    private function calculateHeuristicFakeScore(array $review): int
    {
        $score = 20; // Base score - assume most reviews are somewhat genuine
        
        $text = $review['text'] ?? $review['review_text'] ?? '';
        $rating = (int)($review['rating'] ?? 3);
        
        // Length-based scoring
        $textLength = strlen($text);
        if ($textLength < 20) {
            $score += 30; // Very short reviews are suspicious
        } elseif ($textLength < 50) {
            $score += 15; // Short reviews are somewhat suspicious
        } elseif ($textLength > 500) {
            $score -= 10; // Very detailed reviews are less likely fake
        }
        
        // Rating-based scoring
        if ($rating == 5) {
            $score += 10; // 5-star reviews are more often fake
        } elseif ($rating == 1) {
            $score += 5; // 1-star reviews can be fake attacks
        } elseif ($rating == 3 || $rating == 4) {
            $score -= 5; // More balanced ratings are less suspicious
        }
        
        // Content pattern analysis
        $lowerText = strtolower($text);
        
        // Generic positive patterns
        $genericPhrases = [
            'great product', 'highly recommend', 'best ever', 'amazing quality',
            'love it', 'perfect', 'exactly as described', 'fast shipping'
        ];
        
        $genericCount = 0;
        foreach ($genericPhrases as $phrase) {
            if (str_contains($lowerText, $phrase)) {
                $genericCount++;
            }
        }
        
        if ($genericCount >= 3) {
            $score += 25; // Too many generic phrases
        } elseif ($genericCount >= 2) {
            $score += 15;
        }
        
        // Excessive punctuation or capitals
        if (preg_match('/[!]{3,}/', $text) || preg_match('/[A-Z]{5,}/', $text)) {
            $score += 10;
        }
        
        // Very promotional language
        $promotionalTerms = ['buy', 'purchase', 'deal', 'price', 'money', 'worth'];
        $promotionalCount = 0;
        foreach ($promotionalTerms as $term) {
            if (str_contains($lowerText, $term)) {
                $promotionalCount++;
            }
        }
        
        if ($promotionalCount >= 3) {
            $score += 15;
        }
        
        // Specific product mentions (often genuine)
        if (preg_match('/\b(color|size|material|weight|dimension)\b/', $lowerText)) {
            $score -= 10;
        }
        
        // Personal experience indicators (often genuine)
        $personalTerms = ['i', 'my', 'me', 'family', 'wife', 'husband', 'kids'];
        $personalCount = 0;
        foreach ($personalTerms as $term) {
            if (str_contains($lowerText, ' '.$term.' ')) {
                $personalCount++;
            }
        }
        
        if ($personalCount >= 3) {
            $score -= 15;
        }
        
        // Ensure score is within bounds
        return max(0, min(100, $score));
    }

    /**
     * Generate a summary for fallback analysis
     */
    private function generateFallbackSummary(array $reviews, array $results): string
    {
        $totalReviews = count($reviews);
        $scores = array_column($results, 'score');
        $avgScore = round(array_sum($scores) / count($scores), 1);
        
        $suspicious = count(array_filter($scores, fn($s) => $s >= 60));
        $likely = count(array_filter($scores, fn($s) => $s >= 40 && $s < 60));
        $genuine = count(array_filter($scores, fn($s) => $s < 40));
        
        $suspiciousPercent = round(($suspicious / $totalReviews) * 100, 1);
        
        return "Heuristic Analysis: {$totalReviews} reviews analyzed. ".
               "Average fake score: {$avgScore}/100. ".
               "Distribution: {$genuine} likely genuine, {$likely} questionable, {$suspicious} suspicious ({$suspiciousPercent}% suspicious). ".
               "Note: This is a basic analysis due to OpenAI quota limits. Results may be less accurate than AI analysis.";
    }
}
