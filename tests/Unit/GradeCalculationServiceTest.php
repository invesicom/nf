<?php

namespace Tests\Unit;

use App\Services\GradeCalculationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GradeCalculationServiceTest extends TestCase
{
    #[Test]
    public function it_calculates_grade_a_for_low_fake_percentages()
    {
        $this->assertEquals('A', GradeCalculationService::calculateGrade(0));
        $this->assertEquals('A', GradeCalculationService::calculateGrade(5));
        $this->assertEquals('A', GradeCalculationService::calculateGrade(15));
    }

    #[Test]
    public function it_calculates_grade_b_for_moderate_fake_percentages()
    {
        $this->assertEquals('B', GradeCalculationService::calculateGrade(16));
        $this->assertEquals('B', GradeCalculationService::calculateGrade(25));
        $this->assertEquals('B', GradeCalculationService::calculateGrade(30));
    }

    #[Test]
    public function it_calculates_grade_c_for_medium_fake_percentages()
    {
        $this->assertEquals('C', GradeCalculationService::calculateGrade(31));
        $this->assertEquals('C', GradeCalculationService::calculateGrade(40));
        $this->assertEquals('C', GradeCalculationService::calculateGrade(50));
    }

    #[Test]
    public function it_calculates_grade_d_for_high_fake_percentages()
    {
        $this->assertEquals('D', GradeCalculationService::calculateGrade(51));
        $this->assertEquals('D', GradeCalculationService::calculateGrade(65));
        $this->assertEquals('D', GradeCalculationService::calculateGrade(70));
    }

    #[Test]
    public function it_calculates_grade_f_for_very_high_fake_percentages()
    {
        $this->assertEquals('F', GradeCalculationService::calculateGrade(71));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(85));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(100));
    }

    #[Test]
    public function it_handles_boundary_values_correctly()
    {
        // Test exact boundary values
        $this->assertEquals('A', GradeCalculationService::calculateGrade(15.0));
        $this->assertEquals('B', GradeCalculationService::calculateGrade(15.1));

        $this->assertEquals('B', GradeCalculationService::calculateGrade(30.0));
        $this->assertEquals('C', GradeCalculationService::calculateGrade(30.1));

        $this->assertEquals('C', GradeCalculationService::calculateGrade(50.0));
        $this->assertEquals('D', GradeCalculationService::calculateGrade(50.1));

        $this->assertEquals('D', GradeCalculationService::calculateGrade(70.0));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(70.1));
    }

    #[Test]
    public function it_handles_decimal_values()
    {
        $this->assertEquals('A', GradeCalculationService::calculateGrade(14.9));
        $this->assertEquals('B', GradeCalculationService::calculateGrade(29.5));
        $this->assertEquals('C', GradeCalculationService::calculateGrade(49.99));
        $this->assertEquals('D', GradeCalculationService::calculateGrade(69.1));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(85.7));
    }

    #[Test]
    public function it_handles_edge_cases()
    {
        // Test negative values (shouldn't happen in practice, but handle gracefully)
        $this->assertEquals('A', GradeCalculationService::calculateGrade(-1));
        $this->assertEquals('A', GradeCalculationService::calculateGrade(-10.5));

        // Test very high values
        $this->assertEquals('F', GradeCalculationService::calculateGrade(150));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(999.99));
    }

    #[Test]
    public function it_returns_correct_grade_thresholds()
    {
        $thresholds = GradeCalculationService::getGradeThresholds();

        $this->assertIsArray($thresholds);
        $this->assertArrayHasKey('A', $thresholds);
        $this->assertArrayHasKey('B', $thresholds);
        $this->assertArrayHasKey('C', $thresholds);
        $this->assertArrayHasKey('D', $thresholds);
        $this->assertArrayHasKey('F', $thresholds);

        // Verify threshold values
        $this->assertEquals(['min' => 0, 'max' => 15], $thresholds['A']);
        $this->assertEquals(['min' => 16, 'max' => 30], $thresholds['B']);
        $this->assertEquals(['min' => 31, 'max' => 50], $thresholds['C']);
        $this->assertEquals(['min' => 51, 'max' => 70], $thresholds['D']);
        $this->assertEquals(['min' => 71, 'max' => 100], $thresholds['F']);
    }

    #[Test]
    public function it_returns_correct_grade_descriptions()
    {
        $this->assertEquals(
            'Excellent - Very few fake reviews detected',
            GradeCalculationService::getGradeDescription('A')
        );
        $this->assertEquals(
            'Good - Low fake review percentage',
            GradeCalculationService::getGradeDescription('B')
        );
        $this->assertEquals(
            'Fair - Moderate fake review concerns',
            GradeCalculationService::getGradeDescription('C')
        );
        $this->assertEquals(
            'Poor - High fake review percentage',
            GradeCalculationService::getGradeDescription('D')
        );
        $this->assertEquals(
            'Failing - Majority of reviews appear fake',
            GradeCalculationService::getGradeDescription('F')
        );
        $this->assertEquals(
            'Unknown grade',
            GradeCalculationService::getGradeDescription('X')
        );
    }

    #[Test]
    public function it_maintains_consistency_with_old_thresholds()
    {
        // Test that the new standardized thresholds work correctly
        // These are the thresholds we're standardizing to (A≤15%, B≤30%, C≤50%, D≤70%)

        // Grade A: 0-15%
        $this->assertEquals('A', GradeCalculationService::calculateGrade(0));
        $this->assertEquals('A', GradeCalculationService::calculateGrade(10));
        $this->assertEquals('A', GradeCalculationService::calculateGrade(15));

        // Grade B: 16-30%
        $this->assertEquals('B', GradeCalculationService::calculateGrade(16));
        $this->assertEquals('B', GradeCalculationService::calculateGrade(25));
        $this->assertEquals('B', GradeCalculationService::calculateGrade(30));

        // Grade C: 31-50%
        $this->assertEquals('C', GradeCalculationService::calculateGrade(31));
        $this->assertEquals('C', GradeCalculationService::calculateGrade(40));
        $this->assertEquals('C', GradeCalculationService::calculateGrade(50));

        // Grade D: 51-70%
        $this->assertEquals('D', GradeCalculationService::calculateGrade(51));
        $this->assertEquals('D', GradeCalculationService::calculateGrade(65));
        $this->assertEquals('D', GradeCalculationService::calculateGrade(70));

        // Grade F: 71-100%
        $this->assertEquals('F', GradeCalculationService::calculateGrade(71));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(85));
        $this->assertEquals('F', GradeCalculationService::calculateGrade(100));
    }
}
