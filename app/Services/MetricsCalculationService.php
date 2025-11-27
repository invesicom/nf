<?php

namespace App\Services;

use App\Models\AsinData;

class MetricsCalculationService
{
    private GradeCalculationService $gradeService;

    public function __construct()
    {
        $this->gradeService = new GradeCalculationService();
    }

    /**
     * Calculate final metrics for analyzed product.
     */
    public function calculateFinalMetrics(AsinData $asinData): array
    {
        $policy = app(ProductAnalysisPolicy::class);
        $reviews = $asinData->getReviewsArray();
        $openaiResult = $asinData->openai_result;

        if (!$policy->isAnalyzable($asinData) || empty($openaiResult)) {
            return $policy->getDefaultMetrics();
        }

        // Check if this is aggregate format (new) or individual format (legacy)
        $aggregateData = $this->extractAggregateData($openaiResult);
        
        if (!empty($aggregateData)) {
            // Use aggregate analysis data directly
            $fakePercentage = (float) $aggregateData['fake_percentage'];
            $totalReviews = count($reviews);
            $fakeCount = round(($fakePercentage / 100) * $totalReviews);
            
            // Calculate ratings (simplified since we don't have per-review scores)
            $averageRating = $this->calculateAverageRating($reviews);
            $adjustedRating = $this->calculateAdjustedRatingFromPercentage($averageRating, $fakePercentage);
            
            // Use LLM's enhanced explanation and add product insights
            $grade = $this->gradeService->calculateGrade($fakePercentage);
            $explanation = $this->buildEnhancedExplanation($aggregateData, $totalReviews, $fakeCount, $fakePercentage);
        } else {
            // Legacy individual scoring format
            $detailedScores = $this->extractDetailedScores($openaiResult);

            if (empty($detailedScores)) {
                return $policy->getDefaultMetrics();
            }

            // Calculate metrics from individual scores
            $totalReviews = count($reviews);
            $fakeCount = $this->countFakeReviews($detailedScores);
            $fakePercentage = $totalReviews > 0 ? round(($fakeCount / $totalReviews) * 100, 1) : 0;

            // Calculate ratings
            $averageRating = $this->calculateAverageRating($reviews);
            $adjustedRating = $this->calculateAdjustedRating($reviews, $detailedScores);

            // Generate grade and explanation
            $grade = $this->gradeService->calculateGrade($fakePercentage);
            $explanation = $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);
        }

        // Only update the model if it hasn't been analyzed yet or if metrics are missing
        $needsUpdate = $asinData->status !== 'completed' ||
                      is_null($asinData->fake_percentage) ||
                      is_null($asinData->grade);

        if ($needsUpdate) {
            $asinData->update([
                'fake_percentage'   => $fakePercentage,
                'grade'             => $grade,
                'explanation'       => $explanation,
                'amazon_rating'     => $averageRating,
                'adjusted_rating'   => $adjustedRating,
                'status'            => 'completed',
                'first_analyzed_at' => $asinData->first_analyzed_at ?? now(),
                'last_analyzed_at'  => now(),
            ]);
        }

        return [
            'fake_percentage' => $fakePercentage,
            'grade'           => $grade,
            'explanation'     => $explanation,
            'amazon_rating'   => $averageRating,
            'adjusted_rating' => $adjustedRating,
            'total_reviews'   => $totalReviews,
            'fake_count'      => $fakeCount,
        ];
    }

    /**
     * Extract detailed scores from OpenAI result.
     */
    /**
     * Extract aggregate analysis data from LLM result.
     */
    private function extractAggregateData($openaiResult): array
    {
        if (is_string($openaiResult)) {
            $decoded = json_decode($openaiResult, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $openaiResult = $decoded;
        }

        if (!is_array($openaiResult)) {
            return [];
        }

        // Check if this is aggregate format
        if (isset($openaiResult['fake_percentage'])) {
            return $openaiResult;
        }

        // Check if this is wrapped in a results key
        if (isset($openaiResult['results']) && isset($openaiResult['results']['fake_percentage'])) {
            return $openaiResult['results'];
        }

        return [];
    }

    private function extractDetailedScores($openaiResult): array
    {
        if (is_string($openaiResult)) {
            $decoded = json_decode($openaiResult, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $openaiResult = $decoded;
        }

        return $openaiResult['detailed_scores'] ?? [];
    }

    /**
     * Count fake reviews based on threshold.
     */
    private function countFakeReviews(array $detailedScores): int
    {
        $fakeThreshold = 85; // Reviews with score >= 85 are considered fake

        return collect($detailedScores)->filter(function ($scoreData) use ($fakeThreshold) {
            // Handle new format: {score: 75, label: "fake", confidence: 90, explanation: "..."}
            if (is_array($scoreData) && isset($scoreData['score'])) {
                return is_numeric($scoreData['score']) && $scoreData['score'] >= $fakeThreshold;
            }

            // Handle legacy format: numeric score
            return is_numeric($scoreData) && $scoreData >= $fakeThreshold;
        })->count();
    }

    /**
     * Calculate average rating from reviews.
     */
    private function calculateAverageRating(array $reviews): float
    {
        if (empty($reviews)) {
            return 0.0;
        }

        $totalRating = 0;
        $validReviews = 0;

        foreach ($reviews as $review) {
            if (isset($review['rating']) && is_numeric($review['rating'])) {
                $totalRating += $review['rating'];
                $validReviews++;
            }
        }

        return $validReviews > 0 ? round($totalRating / $validReviews, 2) : 0.0;
    }

    /**
     * Calculate adjusted rating excluding fake reviews.
     */
    private function calculateAdjustedRating(array $reviews, array $detailedScores): float
    {
        if (empty($reviews) || empty($detailedScores)) {
            return $this->calculateAverageRating($reviews);
        }

        $fakeThreshold = 85;
        $genuineRatingSum = 0;
        $genuineCount = 0;

        foreach ($reviews as $index => $review) {
            // Try to get score by review ID first, then by index
            $reviewId = $review['id'] ?? $index;
            $scoreData = $detailedScores[$reviewId] ?? $detailedScores[$index] ?? 0;

            // Extract numeric score from new or legacy format
            $score = 0;
            if (is_array($scoreData) && isset($scoreData['score'])) {
                $score = $scoreData['score']; // New format
            } elseif (is_numeric($scoreData)) {
                $score = $scoreData; // Legacy format
            }

            // Only include genuine reviews (score < threshold)
            if ($score < $fakeThreshold && isset($review['rating']) && is_numeric($review['rating'])) {
                $genuineRatingSum += $review['rating'];
                $genuineCount++;
            }
        }

        if ($genuineCount === 0) {
            // If all reviews are fake, return the original average
            return $this->calculateAverageRating($reviews);
        }

        return round($genuineRatingSum / $genuineCount, 2);
    }

    /**
     * Calculate adjusted rating based on fake percentage (for aggregate analysis).
     */
    private function calculateAdjustedRatingFromPercentage(float $averageRating, float $fakePercentage): float
    {
        if ($averageRating <= 0) {
            return 0.0;
        }

        // Adjust rating based on fake percentage
        // Higher fake percentage = lower adjusted rating
        $adjustmentFactor = 1 - ($fakePercentage / 200); // Max 50% reduction for 100% fake
        $adjustedRating = $averageRating * max(0.5, $adjustmentFactor); // Minimum 50% of original

        return round($adjustedRating, 1);
    }

    /**
     * Generate explanation text for the analysis with proper paragraph breaks.
     */
    private function generateExplanation(int $totalReviews, int $fakeCount, float $fakePercentage): string
    {
        $paragraph1 = "Analysis of {$totalReviews} reviews found {$fakeCount} potentially fake reviews (".round($fakePercentage, 1).'%). ';

        if ($fakePercentage <= 15) {
            $paragraph1 .= 'This product demonstrates excellent review authenticity with very low fake review activity.';
            $paragraph2 = 'The majority of reviews show genuine customer experiences with specific details and balanced perspectives. This indicates a trustworthy product with authentic customer feedback.';
            $paragraph3 = 'Review patterns suggest legitimate customer engagement with minimal artificial manipulation. The authenticity indicators align with genuine product experiences.';
        } elseif ($fakePercentage <= 30) {
            $paragraph1 .= 'This product shows good review authenticity with low fake review activity.';
            $paragraph2 = 'Most customer feedback appears genuine with specific product experiences and realistic expectations. Minor concerns may exist but overall review quality is reliable for purchase decisions.';
            $paragraph3 = 'The review distribution and language patterns suggest predominantly authentic customer feedback with minimal artificial influence.';
        } elseif ($fakePercentage <= 50) {
            $paragraph1 .= 'This product has moderate fake review concerns requiring careful evaluation.';
            $paragraph2 = 'Mixed signals in review authenticity suggest some artificial inflation of ratings. Genuine reviews are present but exercise caution and focus on verified purchase reviews when making decisions.';
            $paragraph3 = 'The analysis reveals a combination of authentic customer experiences alongside potentially manipulated content that may influence overall ratings.';
        } elseif ($fakePercentage <= 70) {
            $paragraph1 .= 'This product shows high fake review activity with significant authenticity concerns.';
            $paragraph2 = 'Many reviews exhibit patterns consistent with artificial generation or incentivized feedback. Genuine customer experiences may be overshadowed by promotional content.';
            $paragraph3 = 'The prevalence of suspicious review patterns suggests coordinated efforts to artificially enhance product ratings. Proceed with caution when evaluating customer feedback.';
        } else {
            $paragraph1 .= 'This product has very high fake review activity with most reviews appearing artificially generated.';
            $paragraph2 = 'Extensive patterns of promotional language, generic praise, and suspicious timing suggest coordinated fake review campaigns. Authentic customer feedback is minimal.';
            $paragraph3 = 'The overwhelming presence of artificial reviews makes it difficult to assess genuine product quality. Consider alternative products with more reliable review profiles.';
        }

        return $paragraph1 . "\n\n" . $paragraph2 . "\n\n" . $paragraph3;
    }

    /**
     * Build enhanced explanation using LLM analysis and product insights.
     */
    private function buildEnhancedExplanation(array $aggregateData, int $totalReviews, int $fakeCount, float $fakePercentage): string
    {
        // Start with LLM's detailed explanation if available
        $explanation = $aggregateData['explanation'] ?? $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);
        
        // Add product insights if available (for SEO enhancement)
        if (!empty($aggregateData['product_insights'])) {
            $explanation .= "\n\nProduct Analysis: " . $aggregateData['product_insights'];
        }
        
        // Add key patterns summary if available
        if (!empty($aggregateData['key_patterns']) && is_array($aggregateData['key_patterns'])) {
            $patterns = implode(', ', array_slice($aggregateData['key_patterns'], 0, 3)); // Limit to 3 patterns
            $explanation .= "\n\nKey patterns identified in the review analysis include: " . $patterns . ".";
        }
        
        return $explanation;
    }
}
