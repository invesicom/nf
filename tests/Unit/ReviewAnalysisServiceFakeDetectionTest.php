<?php

namespace Tests\Unit;

use App\Models\AsinData;
use App\Services\ReviewAnalysisService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ReviewAnalysisServiceFakeDetectionTest extends TestCase
{
    use RefreshDatabase;

    private ReviewAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReviewAnalysisService::class);
    }

    #[Test]
    public function it_correctly_applies_fake_review_threshold_of_70()
    {
        // Create test ASIN data with reviews and OpenAI scores
        $reviews = [
            ['id' => 'review_1', 'rating' => 5, 'review_text' => 'Great product'],
            ['id' => 'review_2', 'rating' => 4, 'review_text' => 'Good quality'],
            ['id' => 'review_3', 'rating' => 5, 'review_text' => 'AMAZING!!! BUY NOW!!!'],
            ['id' => 'review_4', 'rating' => 3, 'review_text' => 'Average product with some pros and cons'],
        ];

        $openaiResult = [
            'detailed_scores' => [
                'review_1' => 65, // Should be genuine (< 70)
                'review_2' => 45, // Should be genuine (< 70)
                'review_3' => 85, // Should be fake (>= 70)
                'review_4' => 25, // Should be genuine (< 70)
            ]
        ];

        $asinData = AsinData::create([
            'asin' => 'B0TEST123',
            'country' => 'us',
            'reviews' => json_encode($reviews),
            'openai_result' => json_encode($openaiResult),
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => 'Test Product'
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        // Verify that only 1 review (score 85) is considered fake
        $this->assertEquals(25.0, $result['fake_percentage']); // 1 fake out of 4 = 25%
        
        // Verify that 3 reviews are considered genuine
        $this->assertArrayHasKey('amazon_rating', $result);
        $this->assertArrayHasKey('adjusted_rating', $result);
        $this->assertArrayHasKey('grade', $result);
    }

    #[Test]
    public function it_calculates_heuristic_scores_with_original_sensitivity()
    {
        // Test the heuristic scoring method directly using reflection
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateHeuristicFakeScore');
        $method->setAccessible(true);

        // Test very short review (should get high penalty)
        $shortReview = [
            'review_text' => 'Great!',
            'rating' => 5
        ];
        $shortScore = $method->invoke($this->service, $shortReview);
        $this->assertGreaterThan(50, $shortScore); // Base 20 + short text 30 + 5-star 10 = 60+

        // Test detailed review (should get bonus)
        $detailedReview = [
            'review_text' => str_repeat('This is a very detailed review with lots of specific information about the product features, quality, performance, and user experience. ', 10),
            'rating' => 4
        ];
        $detailedScore = $method->invoke($this->service, $detailedReview);
        $this->assertLessThan(20, $detailedScore); // Base 20 + detailed bonus -10 + balanced rating -5 = 5

        // Test generic promotional review
        $genericReview = [
            'review_text' => 'Great product, highly recommend, amazing quality, love it!',
            'rating' => 5
        ];
        $genericScore = $method->invoke($this->service, $genericReview);
        $this->assertGreaterThan(50, $genericScore); // Should have high score due to generic phrases (base 20 + 5-star 10 + generic phrases 25 = 55)
    }

    #[Test]
    public function it_handles_missing_openai_scores_gracefully()
    {
        $reviews = [
            ['id' => 'review_1', 'rating' => 5, 'review_text' => 'Great product'],
            ['id' => 'review_2', 'rating' => 1, 'review_text' => 'Terrible'],
        ];

        $openaiResult = [
            'detailed_scores' => [
                'review_1' => 60, // Only one score provided
                // review_2 missing - should default to 0
            ]
        ];

        $asinData = AsinData::create([
            'asin' => 'B0TEST456',
            'country' => 'us',
            'reviews' => json_encode($reviews),
            'openai_result' => json_encode($openaiResult),
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => 'Test Product'
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        // Should complete without errors
        $this->assertIsArray($result);
        $this->assertArrayHasKey('fake_percentage', $result);
        $this->assertEquals(0.0, $result['fake_percentage']); // No reviews >= 70
    }

    #[Test]
    public function it_maintains_consistent_rating_calculations()
    {
        $reviews = [
            ['id' => 'review_1', 'rating' => 5, 'review_text' => 'Excellent'],
            ['id' => 'review_2', 'rating' => 4, 'review_text' => 'Good'],
            ['id' => 'review_3', 'rating' => 3, 'review_text' => 'OK'],
            ['id' => 'review_4', 'rating' => 2, 'review_text' => 'Poor'],
        ];

        $openaiResult = [
            'detailed_scores' => [
                'review_1' => 75, // Fake - should be excluded from rating calculation
                'review_2' => 45, // Genuine
                'review_3' => 30, // Genuine  
                'review_4' => 25, // Genuine
            ]
        ];

        $asinData = AsinData::create([
            'asin' => 'B0TEST789',
            'country' => 'us',
            'reviews' => json_encode($reviews),
            'openai_result' => json_encode($openaiResult),
            'status' => 'completed',
            'have_product_data' => true,
            'product_title' => 'Test Product'
        ]);

        $result = $this->service->calculateFinalMetrics($asinData);

        // Amazon rating should include all reviews: (5+4+3+2)/4 = 3.5
        $this->assertEquals(3.5, $result['amazon_rating']);
        
        // Adjusted rating should exclude fake review: (4+3+2)/3 = 3.0
        $this->assertEquals(3.0, $result['adjusted_rating']);
        
        // Fake percentage: 1 fake out of 4 = 25%
        $this->assertEquals(25.0, $result['fake_percentage']);
    }
}
