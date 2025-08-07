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
        'detailed_analysis',
        'fake_review_examples',
        'fake_percentage',
        'amazon_rating',
        'adjusted_rating',
        'grade',
        'explanation',
        'status',
        'analysis_notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'reviews'                  => 'array',
        'openai_result'            => 'array',
        'detailed_analysis'        => 'array',
        'fake_review_examples'     => 'array',
        'have_product_data'        => 'boolean',
        'product_data_scraped_at'  => 'datetime',
    ];



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
