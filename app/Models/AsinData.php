<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model for Amazon product review analysis data.
 */
class AsinData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'asin_data';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'asin',
        'country',
        'product_description',
        'product_title',
        'product_image_url',
        'have_product_data',
        'product_data_scraped_at',
        'reviews',
        'openai_result',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'reviews'                  => 'array',
        'openai_result'            => 'array',
        'have_product_data'        => 'boolean',
        'product_data_scraped_at'  => 'datetime',
    ];

    /**
     * Calculate fake review percentage dynamically.
     *
     * @return float|null Percentage of fake reviews (0-100) or null if no data
     */
    public function getFakePercentageAttribute(): ?float
    {
        $reviews = $this->getReviewsArray();
        $openaiResultJson = is_string($this->openai_result) ? $this->openai_result : json_encode($this->openai_result);
        $openaiResult = json_decode($openaiResultJson, true);

        if (empty($reviews) || !$openaiResult || !isset($openaiResult['detailed_scores'])) {
            return null;
        }

        $totalReviews = count($reviews);
        $fakeCount = 0;

        foreach ($openaiResult['detailed_scores'] as $reviewId => $score) {
            if ($score >= 70) {
                $fakeCount++;
            }
        }

        return $totalReviews > 0 ? round(($fakeCount / $totalReviews) * 100, 1) : 0;
    }

    /**
     * Calculate product grade based on fake review percentage.
     *
     * @return string|null Letter grade (A-F) or null if no data
     */
    public function getGradeAttribute(): ?string
    {
        $fakePercentage = $this->fake_percentage;

        if ($fakePercentage === null) {
            return null;
        }

        if ($fakePercentage >= 50) {
            return 'F';
        } elseif ($fakePercentage >= 30) {
            return 'D';
        } elseif ($fakePercentage >= 20) {
            return 'C';
        } elseif ($fakePercentage >= 10) {
            return 'B';
        } else {
            return 'A';
        }
    }

    /**
     * Generate human-readable analysis explanation.
     *
     * @return string|null Analysis explanation or null if no data
     */
    public function getExplanationAttribute(): ?string
    {
        $reviews = $this->getReviewsArray();
        $fakePercentage = $this->fake_percentage;

        if (empty($reviews) || $fakePercentage === null) {
            return null;
        }

        $totalReviews = count($reviews);
        $fakeCount = round(($fakePercentage / 100) * $totalReviews);

        $explanation = "Analysis of {$totalReviews} reviews found {$fakeCount} potentially fake reviews ({$fakePercentage}%). ";

        if ($fakePercentage >= 50) {
            $explanation .= 'This product has an extremely high percentage of fake reviews. Avoid purchasing.';
        } elseif ($fakePercentage >= 30) {
            $explanation .= 'This product has a high percentage of fake reviews. Consider looking for alternatives.';
        } elseif ($fakePercentage >= 20) {
            $explanation .= 'This product has moderate fake review activity. Exercise some caution.';
        } elseif ($fakePercentage >= 10) {
            $explanation .= 'This product has some fake review activity but is generally trustworthy.';
        } else {
            $explanation .= 'This product appears to have genuine reviews with minimal fake activity.';
        }

        return $explanation;
    }

    /**
     * Calculate the original Amazon product rating.
     *
     * @return float Average rating from all reviews
     */
    public function getAmazonRatingAttribute(): float
    {
        $reviews = $this->getReviewsArray();

        if (empty($reviews)) {
            return 0;
        }

        $totalRating = collect($reviews)->sum('rating');

        return round($totalRating / count($reviews), 2);
    }

    /**
     * Calculate adjusted rating after removing fake reviews.
     *
     * @return float Adjusted rating based on genuine reviews only
     */
    public function getAdjustedRatingAttribute(): float
    {
        $reviews = $this->getReviewsArray();
        $openaiResult = is_array($this->openai_result) ? $this->openai_result : json_decode($this->openai_result ?? '[]', true);

        if (empty($reviews) || !$openaiResult || !isset($openaiResult['results'])) {
            return $this->amazon_rating;
        }

        $results = $openaiResult['results'];
        $genuineRatingSum = 0;
        $genuineCount = 0;

        foreach ($reviews as $index => $review) {
            $score = $results[$index]['score'] ?? 0;

            // Scores < 80 are considered genuine
            if ($score < 80) {
                $genuineRatingSum += $review['rating'];
                $genuineCount++;
            }
        }

        if ($genuineCount === 0) {
            return $this->amazon_rating;
        }

        return round($genuineRatingSum / $genuineCount, 2);
    }

    /**
     * Helper method to safely get reviews as array.
     *
     * @return array<int, array> Array of review data
     */
    public function getReviewsArray(): array
    {
        $reviews = $this->reviews;

        // If it's already an array, return it
        if (is_array($reviews)) {
            return $reviews;
        }

        // If it's a string, decode it
        if (is_string($reviews)) {
            $decoded = json_decode($reviews, true);

            return is_array($decoded) ? $decoded : [];
        }

        // Default to empty array
        return [];
    }

    /**
     * Check if the product has been fully analyzed.
     *
     * @return bool True if analysis is complete, false otherwise
     */
    public function isAnalyzed(): bool
    {
        // Check if we have OpenAI results
        $openaiResult = is_array($this->openai_result) ? $this->openai_result : json_decode($this->openai_result ?? '[]', true);

        return !empty($openaiResult) &&
               is_array($openaiResult) &&
               !empty($this->getReviewsArray());
    }

    /**
     * Generate a URL-friendly slug from the product title.
     *
     * @return string|null The slug or null if no title
     */
    public function getSlugAttribute(): ?string
    {
        if (empty($this->product_title)) {
            return null;
        }

        // Convert to lowercase and replace spaces/special chars with hyphens
        $slug = strtolower($this->product_title);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');

        // Limit length to 60 characters for SEO
        if (strlen($slug) > 60) {
            $slug = substr($slug, 0, 60);
            $slug = preg_replace('/-[^-]*$/', '', $slug); // Remove partial words
        }

        return $slug ?: null;
    }

    /**
     * Generate the SEO-friendly URL for this product.
     *
     * @return string The SEO-friendly URL path
     */
    public function getSeoUrlAttribute(): string
    {
        $slug = $this->slug;
        
        if ($slug) {
            return "/amazon/{$this->asin}/{$slug}";
        }
        
        // Fallback to basic URL if no slug available
        return "/amazon/{$this->asin}";
    }
}
