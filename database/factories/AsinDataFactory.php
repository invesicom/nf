<?php

namespace Database\Factories;

use App\Models\AsinData;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AsinData>
 */
class AsinDataFactory extends Factory
{
    protected $model = AsinData::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asin'                    => $this->faker->unique()->regexify('B0[A-Z0-9]{8}'),
            'country'                 => 'US',
            'status'                  => 'completed',
            'fake_percentage'         => $this->faker->randomFloat(1, 0, 100),
            'grade'                   => $this->faker->randomElement(['A', 'B', 'C', 'D', 'F']),
            'have_product_data'       => true,
            'total_reviews_on_amazon' => $this->faker->numberBetween(50, 5000),
            'product_title'           => $this->faker->sentence(4),
            'product_image_url'       => $this->faker->imageUrl(300, 300, 'products'),
            'product_description'     => $this->faker->paragraph(),
            'openai_result'           => json_encode([
                'fake_indicators'    => $this->faker->sentences(3),
                'overall_assessment' => $this->faker->paragraph(),
                'confidence_score'   => $this->faker->randomFloat(2, 0.5, 1.0),
            ]),
            'reviews' => json_encode([
                [
                    'rating'        => $this->faker->numberBetween(1, 5),
                    'text'          => $this->faker->paragraph(),
                    'helpful_votes' => $this->faker->numberBetween(0, 100),
                ],
            ]),
            'amazon_rating'   => $this->faker->randomFloat(1, 1, 5),
            'adjusted_rating' => $this->faker->randomFloat(1, 1, 5),
            'explanation'     => $this->faker->paragraph(),
        ];
    }

    /**
     * Indicate that the product is still processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'          => 'processing',
            'fake_percentage' => null,
            'grade'           => null,
            'openai_result'   => null,
        ]);
    }

    /**
     * Indicate that the product failed analysis.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'          => 'failed',
            'fake_percentage' => null,
            'grade'           => null,
            'openai_result'   => null,
        ]);
    }

    /**
     * Indicate that the product doesn't have product data.
     */
    public function withoutProductData(): static
    {
        return $this->state(fn (array $attributes) => [
            'have_product_data'    => false,
            'product_title'        => null,
            'product_image_url'    => null,
            'product_price'        => null,
            'product_rating'       => null,
            'product_review_count' => null,
        ]);
    }

    /**
     * Indicate that the product has a specific grade.
     */
    public function gradeA(): static
    {
        return $this->state(fn (array $attributes) => [
            'fake_percentage' => $this->faker->randomFloat(1, 0, 10),
            'grade'           => 'A',
        ]);
    }

    /**
     * Indicate that the product has a specific grade.
     */
    public function gradeF(): static
    {
        return $this->state(fn (array $attributes) => [
            'fake_percentage' => $this->faker->randomFloat(1, 80, 100),
            'grade'           => 'F',
        ]);
    }

    /**
     * Indicate that the product has completed price analysis.
     */
    public function withPriceAnalysis(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_analysis_status' => 'completed',
            'price_analyzed_at'     => now(),
            'price_analysis'        => [
                'msrp_analysis' => [
                    'estimated_msrp'          => '$49.99',
                    'msrp_source'             => 'Product category average',
                    'amazon_price_assessment' => 'Below MSRP',
                ],
                'market_comparison' => [
                    'price_positioning'          => 'Mid-range',
                    'typical_alternatives_range' => '$30-$70',
                    'value_proposition'          => 'Good value for the features offered.',
                ],
                'price_insights' => [
                    'seasonal_consideration' => 'Consider waiting for Black Friday deals.',
                    'deal_indicators'        => 'Look for 20%+ discounts.',
                    'caution_flags'          => 'Unusually low prices may indicate counterfeit.',
                ],
                'summary' => 'This product is competitively priced in its category. The current Amazon price appears to be below the typical MSRP, making it a reasonable purchase.',
            ],
        ]);
    }

    /**
     * Indicate that price analysis is processing.
     */
    public function priceAnalysisProcessing(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_analysis_status' => 'processing',
            'price_analyzed_at'     => null,
            'price_analysis'        => null,
        ]);
    }

    /**
     * Indicate that price analysis failed.
     */
    public function priceAnalysisFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_analysis_status' => 'failed',
            'price_analyzed_at'     => null,
            'price_analysis'        => null,
        ]);
    }
}
