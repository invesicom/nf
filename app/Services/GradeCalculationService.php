<?php

namespace App\Services;

class GradeCalculationService
{
    /**
     * Calculate grade from fake percentage using consistent thresholds.
     *
     * @param float $fakePercentage
     *
     * @return string
     */
    public static function calculateGrade(float $fakePercentage): string
    {
        // Standardized grade thresholds - SINGLE SOURCE OF TRUTH
        if ($fakePercentage <= 15) {
            return 'A';
        }
        if ($fakePercentage <= 30) {
            return 'B';
        }
        if ($fakePercentage <= 50) {
            return 'C';
        }
        if ($fakePercentage <= 70) {
            return 'D';
        }

        return 'F';
    }

    /**
     * Get grade thresholds for reference.
     *
     * @return array
     */
    public static function getGradeThresholds(): array
    {
        return [
            'A' => ['min' => 0, 'max' => 15],
            'B' => ['min' => 16, 'max' => 30],
            'C' => ['min' => 31, 'max' => 50],
            'D' => ['min' => 51, 'max' => 70],
            'F' => ['min' => 71, 'max' => 100],
        ];
    }

    /**
     * Get grade description.
     *
     * @param string $grade
     *
     * @return string
     */
    public static function getGradeDescription(string $grade): string
    {
        return match ($grade) {
            'A'     => 'Excellent - Very few fake reviews detected',
            'B'     => 'Good - Low fake review percentage',
            'C'     => 'Fair - Moderate fake review concerns',
            'D'     => 'Poor - High fake review percentage',
            'F'     => 'Failing - Majority of reviews appear fake',
            'U'     => 'Unanalyzable - No reviews available for analysis',
            default => 'Unknown grade'
        };
    }
}
