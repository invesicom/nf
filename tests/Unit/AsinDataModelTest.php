<?php

namespace Tests\Unit;

use App\Models\AsinData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsinDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_asin_data()
    {
        $asinData = AsinData::create([
            'asin'                => 'B08N5WRWNW',
            'country'             => 'us',
            'product_description' => 'Test Product',
            'reviews'             => [
                ['rating' => 5, 'text' => 'Great product'],
                ['rating' => 4, 'text' => 'Good product'],
                ['rating' => 1, 'text' => 'Bad product'],
            ],
            'openai_result' => [
                'detailed_scores' => [
                    0 => 30, // genuine
                    1 => 45, // genuine
                    2 => 85,  // fake
                ],
            ],
        ]);

        $this->assertInstanceOf(AsinData::class, $asinData);
        $this->assertEquals('B08N5WRWNW', $asinData->asin);
        $this->assertEquals('us', $asinData->country);
        $this->assertEquals('Test Product', $asinData->product_description);
    }

    public function test_fake_percentage_stored_in_database()
    {
        $asinData = AsinData::create([
            'asin'                => 'B08N5WRWNW',
            'country'             => 'us',
            'product_description' => 'Test Product',
            'reviews'             => [
                ['rating' => 5, 'text' => 'Great product'],
                ['rating' => 4, 'text' => 'Good product'],
                ['rating' => 1, 'text' => 'Bad product'],
                ['rating' => 2, 'text' => 'Another bad product'],
            ],
            'openai_result' => [
                'detailed_scores' => [
                    0 => 30, // genuine
                    1 => 45, // genuine
                    2 => 85, // fake (>= 70)
                    3 => 75,  // fake (>= 70)
                ],
            ],
            'fake_percentage' => 50.0, // Now stored directly in database
        ]);

        // Should read from database
        $this->assertEquals(50.0, $asinData->fake_percentage);
    }

    public function test_fake_percentage_returns_null_when_no_data()
    {
        $asinData = AsinData::create([
            'asin'                => 'B08N5WRWNW',
            'country'             => 'us',
            'product_description' => 'Test Product',
            'reviews'             => [],
            'openai_result'       => null,
        ]);

        $this->assertNull($asinData->fake_percentage);
    }

    public function test_grade_stored_in_database()
    {
        // Test Grade A
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => array_fill(0, 10, ['rating' => 5, 'text' => 'Great']),
            'openai_result' => [
                'detailed_scores' => array_fill(0, 10, 30), // all genuine
            ],
            'grade' => 'A', // Now stored directly in database
        ]);
        $this->assertEquals('A', $asinData->grade);

        // Test updating grades
        $asinData->update(['grade' => 'B']);
        $this->assertEquals('B', $asinData->fresh()->grade);

        $asinData->update(['grade' => 'C']);
        $this->assertEquals('C', $asinData->fresh()->grade);

        $asinData->update(['grade' => 'D']);
        $this->assertEquals('D', $asinData->fresh()->grade);

        $asinData->update(['grade' => 'F']);
        $this->assertEquals('F', $asinData->fresh()->grade);
    }

    public function test_explanation_stored_in_database()
    {
        $explanation = 'Analysis of 10 reviews found 2 potentially fake reviews (20%). This product has moderate fake review activity. Exercise some caution.';
        
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => array_fill(0, 10, ['rating' => 5, 'text' => 'Great']),
            'openai_result' => [
                'detailed_scores' => array_merge(
                    array_fill(0, 8, 30), // 8 genuine
                    array_fill(0, 2, 75)  // 2 fake = 20%
                ),
            ],
            'explanation' => $explanation, // Now stored directly in database
        ]);

        $this->assertEquals($explanation, $asinData->explanation);
        $this->assertStringContainsString('Analysis of 10 reviews', $asinData->explanation);
        $this->assertStringContainsString('2 potentially fake reviews', $asinData->explanation);
        $this->assertStringContainsString('20%', $asinData->explanation);
        $this->assertStringContainsString('moderate fake review activity', $asinData->explanation);
    }

    public function test_amazon_rating_stored_in_database()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],
                ['rating' => 4, 'text' => 'Good'],
                ['rating' => 3, 'text' => 'OK'],
                ['rating' => 2, 'text' => 'Bad'],
            ],
            'openai_result' => [],
            'amazon_rating' => 3.5, // Now stored directly in database
        ]);

        // Should read from database
        $this->assertEquals(3.5, $asinData->amazon_rating);
    }

    public function test_adjusted_rating_stored_in_database()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],  // genuine (score 30)
                ['rating' => 4, 'text' => 'Good'],   // genuine (score 45)
                ['rating' => 1, 'text' => 'Bad'],    // fake (score 85)
                ['rating' => 1, 'text' => 'Awful'],   // fake (score 90)
            ],
            'openai_result' => [
                'results' => [
                    ['score' => 30], // genuine
                    ['score' => 45], // genuine
                    ['score' => 85], // fake
                    ['score' => 90],  // fake
                ],
            ],
            'adjusted_rating' => 4.5, // Now stored directly in database
        ]);

        // Should read from database
        $this->assertEquals(4.5, $asinData->adjusted_rating);
    }

    public function test_get_reviews_array_method()
    {
        $reviews = [
            ['rating' => 5, 'text' => 'Great'],
            ['rating' => 4, 'text' => 'Good'],
        ];

        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => $reviews,
        ]);

        $this->assertEquals($reviews, $asinData->getReviewsArray());
    }

    public function test_get_reviews_array_handles_string_json()
    {
        $reviews = [
            ['rating' => 5, 'text' => 'Great'],
            ['rating' => 4, 'text' => 'Good'],
        ];

        $asinData = new AsinData([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => json_encode($reviews), // Store as JSON string
        ]);

        $this->assertEquals($reviews, $asinData->getReviewsArray());
    }

    public function test_is_analyzed_method()
    {
        // Test with no OpenAI result
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [['rating' => 5, 'text' => 'Great']],
            'openai_result' => null,
        ]);
        $this->assertFalse($asinData->isAnalyzed());

        // Test with empty OpenAI result
        $asinData->update(['openai_result' => []]);
        $this->assertFalse($asinData->fresh()->isAnalyzed());

        // Test with OpenAI result and reviews
        $asinData->update(['openai_result' => ['detailed_scores' => []]]);
        $this->assertTrue($asinData->fresh()->isAnalyzed());

        // Test with OpenAI result but no reviews
        $asinDataNoReviews = AsinData::create([
            'asin'          => 'B08N5WRWN1',
            'country'       => 'us',
            'reviews'       => [],
            'openai_result' => ['detailed_scores' => []],
        ]);
        $this->assertFalse($asinDataNoReviews->isAnalyzed());
    }

    public function test_unique_constraint_on_asin_and_country()
    {
        AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
        ]);
    }

    public function test_can_create_same_asin_different_country()
    {
        $asinData1 = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
        ]);

        $asinData2 = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'uk',
        ]);

        $this->assertNotEquals($asinData1->id, $asinData2->id);
        $this->assertEquals('us', $asinData1->country);
        $this->assertEquals('uk', $asinData2->country);
    }

    public function test_fake_percentage_with_string_openai_result()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],
                ['rating' => 1, 'text' => 'Bad'],
            ],
            'openai_result' => json_encode([
                'detailed_scores' => [
                    0 => 30, // genuine
                    1 => 85,  // fake
                ],
            ]),
            'fake_percentage' => 50.0, // Now stored directly in database
        ]);

        // Should read from database
        $this->assertEquals(50.0, $asinData->fake_percentage);
    }

    public function test_fake_percentage_with_missing_detailed_scores()
    {
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [['rating' => 5, 'text' => 'Great']],
            'openai_result' => ['other_data' => 'value'], // No detailed_scores
        ]);

        $this->assertNull($asinData->fake_percentage);
    }

    public function test_grade_returns_null_when_no_fake_percentage()
    {
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [],
            'openai_result' => null,
        ]);

        $this->assertNull($asinData->grade);
    }

    public function test_explanation_returns_null_when_no_data()
    {
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [],
            'openai_result' => null,
        ]);

        $this->assertNull($asinData->explanation);
    }

    public function test_explanation_different_fake_percentage_ranges()
    {
        // Test >= 50% fake (F grade)
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => array_fill(0, 10, ['rating' => 5, 'text' => 'Great']),
            'openai_result' => [
                'detailed_scores' => array_fill(0, 10, 75), // all fake = 100%
            ],
        ]);
        $this->assertStringContainsString('extremely high percentage', $asinData->explanation);
        $this->assertStringContainsString('Avoid purchasing', $asinData->explanation);

        // Test >= 30% fake (D grade)
        $asinData->update([
            'openai_result' => [
                'detailed_scores' => array_merge(
                    array_fill(0, 6, 30), // 6 genuine
                    array_fill(0, 4, 75)  // 4 fake = 40%
                ),
            ],
        ]);
        $explanation = $asinData->fresh()->explanation;
        $this->assertStringContainsString('high percentage', $explanation);
        $this->assertStringContainsString('Consider looking for alternatives', $explanation);

        // Test >= 20% fake (C grade)
        $asinData->update([
            'openai_result' => [
                'detailed_scores' => array_merge(
                    array_fill(0, 8, 30), // 8 genuine
                    array_fill(0, 2, 75)  // 2 fake = 20%
                ),
            ],
        ]);
        $explanation = $asinData->fresh()->explanation;
        $this->assertStringContainsString('moderate fake review activity', $explanation);
        $this->assertStringContainsString('Exercise some caution', $explanation);

        // Test 10-19% fake (B grade)
        $asinData->update([
            'openai_result' => [
                'detailed_scores' => array_merge(
                    array_fill(0, 9, 30), // 9 genuine
                    [75] // 1 fake = 10%
                ),
            ],
        ]);
        $explanation = $asinData->fresh()->explanation;
        $this->assertStringContainsString('some fake review activity', $explanation);
        $this->assertStringContainsString('generally trustworthy', $explanation);

        // Test < 10% fake (A grade)
        $asinData->update([
            'openai_result' => [
                'detailed_scores' => array_merge(
                    array_fill(0, 10, 30), // 10 genuine
                    [] // 0 fake = 0%
                ),
            ],
        ]);
        $explanation = $asinData->fresh()->explanation;
        $this->assertStringContainsString('genuine reviews', $explanation);
        $this->assertStringContainsString('minimal fake activity', $explanation);
    }

    public function test_amazon_rating_with_empty_reviews()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [],
        ]);

        $this->assertEquals(0, $asinData->amazon_rating);
    }

    public function test_adjusted_rating_with_no_openai_results()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],
                ['rating' => 3, 'text' => 'OK'],
            ],
            'openai_result' => null,
        ]);

        // Should fall back to amazon_rating
        $this->assertEquals(4.0, $asinData->adjusted_rating); // (5+3)/2 = 4.0
    }

    public function test_adjusted_rating_with_missing_results_key()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],
                ['rating' => 3, 'text' => 'OK'],
            ],
            'openai_result' => ['other_data' => 'value'], // No 'results' key
        ]);

        // Should fall back to amazon_rating
        $this->assertEquals(4.0, $asinData->adjusted_rating);
    }

    public function test_adjusted_rating_with_all_fake_reviews()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],
                ['rating' => 5, 'text' => 'Amazing'],
            ],
            'openai_result' => [
                'results' => [
                    ['score' => 85], // fake
                    ['score' => 90],  // fake
                ],
            ],
        ]);

        // All reviews are fake, should fall back to amazon_rating
        $this->assertEquals(5.0, $asinData->adjusted_rating); // (5+5)/2 = 5.0
    }

    public function test_adjusted_rating_with_string_openai_result()
    {
        $asinData = AsinData::create([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => [
                ['rating' => 5, 'text' => 'Great'],
                ['rating' => 1, 'text' => 'Bad'],
            ],
            'openai_result' => json_encode([
                'results' => [
                    ['score' => 30], // genuine
                    ['score' => 85],  // fake
                ],
            ]),
        ]);

        // Only genuine review: 5/1 = 5.0
        $this->assertEquals(5.0, $asinData->adjusted_rating);
    }

    public function test_get_reviews_array_with_invalid_json()
    {
        $asinData = new AsinData([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => 'invalid json string',
        ]);

        $this->assertEquals([], $asinData->getReviewsArray());
    }

    public function test_get_reviews_array_with_null_reviews()
    {
        $asinData = new AsinData([
            'asin'    => 'B08N5WRWNW',
            'country' => 'us',
            'reviews' => null,
        ]);

        $this->assertEquals([], $asinData->getReviewsArray());
    }

    public function test_is_analyzed_with_string_openai_result()
    {
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [['rating' => 5, 'text' => 'Great']],
            'openai_result' => json_encode(['detailed_scores' => [0 => 25]]),
        ]);

        $this->assertTrue($asinData->isAnalyzed());
    }

    public function test_is_analyzed_with_invalid_json_openai_result()
    {
        $asinData = new AsinData([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [['rating' => 5, 'text' => 'Great']],
            'openai_result' => 'invalid json',
        ]);

        $this->assertFalse($asinData->isAnalyzed());
    }

    public function test_model_casts()
    {
        $asinData = AsinData::create([
            'asin'          => 'B08N5WRWNW',
            'country'       => 'us',
            'reviews'       => [['rating' => 5, 'text' => 'Great']],
            'openai_result' => ['detailed_scores' => [0 => 25]],
        ]);

        // Test that arrays are properly cast
        $this->assertIsArray($asinData->reviews);
        $this->assertIsArray($asinData->openai_result);
    }

    public function test_fillable_attributes()
    {
        $fillable = (new AsinData())->getFillable();

        $expectedFillable = [
            'asin',
            'country',
            'product_description',
            'product_title',
            'product_image_url',
            'have_product_data',
            'product_data_scraped_at',
            'reviews',
            'openai_result',
            'fake_percentage',
            'amazon_rating',
            'adjusted_rating',
            'grade',
            'explanation',
            'status',
            'analysis_notes',
        ];

        $this->assertEquals($expectedFillable, $fillable);
    }

    public function test_table_name()
    {
        $asinData = new AsinData();
        $this->assertEquals('asin_data', $asinData->getTable());
    }
}
