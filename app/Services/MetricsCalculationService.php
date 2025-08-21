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

        // Parse OpenAI results
        $detailedScores = $this->extractDetailedScores($openaiResult);

        if (empty($detailedScores)) {
            return $policy->getDefaultMetrics();
        }

        // Calculate metrics
        $totalReviews = count($reviews);
        $fakeCount = $this->countFakeReviews($detailedScores);
        $fakePercentage = $totalReviews > 0 ? round(($fakeCount / $totalReviews) * 100, 1) : 0;

        // Calculate ratings
        $averageRating = $this->calculateAverageRating($reviews);
        $adjustedRating = $this->calculateAdjustedRating($reviews, $detailedScores);

        // Generate grade and explanation
        $grade = $this->gradeService->calculateGrade($fakePercentage);
        $explanation = $this->generateExplanation($totalReviews, $fakeCount, $fakePercentage);

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
     * Generate explanation text for the analysis.
     */
    private function generateExplanation(int $totalReviews, int $fakeCount, float $fakePercentage): string
    {
        $explanation = "Analysis of {$totalReviews} reviews found {$fakeCount} potentially fake reviews (".round($fakePercentage, 1).'%). ';

        if ($fakePercentage <= 15) {
            $explanation .= 'This product has very low fake review activity and appears highly trustworthy.';
        } elseif ($fakePercentage <= 30) {
            $explanation .= 'This product has low fake review activity with mostly genuine customer feedback.';
        } elseif ($fakePercentage <= 50) {
            $explanation .= 'This product has moderate fake review concerns. Exercise caution when evaluating reviews.';
        } elseif ($fakePercentage <= 70) {
            $explanation .= 'This product has high fake review activity. Many reviews may not be from genuine customers.';
        } else {
            $explanation .= 'This product has very high fake review activity. Most reviews appear to be artificially generated.';
        }

        return $explanation;
    }
}
