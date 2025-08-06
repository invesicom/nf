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
            'asin' => $this->faker->unique()->regexify('B0[A-Z0-9]{8}'),
            'country' => 'US',
            'status' => 'completed',
            'fake_percentage' => $this->faker->randomFloat(1, 0, 100),
            'grade' => $this->faker->randomElement(['A', 'B', 'C', 'D', 'F']),
            'have_product_data' => true,
            'product_title' => $this->faker->sentence(4),
            'product_image_url' => $this->faker->imageUrl(300, 300, 'products'),
            'product_description' => $this->faker->paragraph(),
            'openai_result' => json_encode([
                'fake_indicators' => $this->faker->sentences(3),
                'overall_assessment' => $this->faker->paragraph(),
                'confidence_score' => $this->faker->randomFloat(2, 0.5, 1.0),
            ]),
            'reviews' => json_encode([
                [
                    'rating' => $this->faker->numberBetween(1, 5),
                    'text' => $this->faker->paragraph(),
                    'helpful_votes' => $this->faker->numberBetween(0, 100),
                ]
            ]),
            'amazon_rating' => $this->faker->randomFloat(1, 1, 5),
            'adjusted_rating' => $this->faker->randomFloat(1, 1, 5),
            'explanation' => $this->faker->paragraph(),
        ];
    }

    /**
     * Indicate that the product is still processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'fake_percentage' => null,
            'grade' => null,
            'openai_result' => null,
        ]);
    }

    /**
     * Indicate that the product failed analysis.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'fake_percentage' => null,
            'grade' => null,
            'openai_result' => null,
        ]);
    }

    /**
     * Indicate that the product doesn't have product data.
     */
    public function withoutProductData(): static
    {
        return $this->state(fn (array $attributes) => [
            'have_product_data' => false,
            'product_title' => null,
            'product_image_url' => null,
            'product_price' => null,
            'product_rating' => null,
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
            'grade' => 'A',
        ]);
    }

    /**
     * Indicate that the product has a specific grade.
     */
    public function gradeF(): static
    {
        return $this->state(fn (array $attributes) => [
            'fake_percentage' => $this->faker->randomFloat(1, 80, 100),
            'grade' => 'F',
        ]);
    }
}