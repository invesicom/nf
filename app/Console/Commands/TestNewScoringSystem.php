<?php

namespace App\Console\Commands;

use App\Services\Providers\OllamaProvider;
use Illuminate\Console\Command;

class TestNewScoringSystem extends Command
{
    protected $signature = 'test:new-scoring {--asin= : ASIN to test with}';
    protected $description = 'Test the new research-based scoring system with sample reviews';

    public function handle()
    {
        $asin = $this->option('asin');

        if (!$asin) {
            // Use sample reviews for testing
            $sampleReviews = $this->getSampleReviews();
            $this->info("Testing new scoring system with sample reviews...\n");
        } else {
            // Get reviews from database
            $asinData = \App\Models\AsinData::where('asin', $asin)->first();
            if (!$asinData || !$asinData->reviews) {
                $this->error("ASIN {$asin} not found or has no reviews");

                return 1;
            }

            $allReviews = json_decode($asinData->reviews, true);
            $sampleReviews = array_slice($allReviews, 0, 5);

            // Ensure meta_data exists for each review
            foreach ($sampleReviews as &$review) {
                if (!isset($review['meta_data'])) {
                    $review['meta_data'] = ['verified_purchase' => false];
                }
            }
            $this->info("Testing new scoring system with 5 reviews from ASIN {$asin}...\n");
        }

        try {
            $provider = new OllamaProvider();

            if (!$provider->isAvailable()) {
                $this->error('OLLAMA service is not available. Please ensure it is running.');

                return 1;
            }

            $this->info('Provider: '.$provider->getProviderName());
            $this->info('Analyzing '.count($sampleReviews)." reviews...\n");

            $result = $provider->analyzeReviews($sampleReviews);

            $this->displayResults($result, $sampleReviews);
        } catch (\Exception $e) {
            $this->error('Analysis failed: '.$e->getMessage());

            return 1;
        }

        return 0;
    }

    private function getSampleReviews(): array
    {
        return [
            [
                'id'          => 'TEST001',
                'rating'      => 5,
                'review_text' => 'Amazing product! Perfect! Incredible quality! Best purchase ever! Highly recommend to everyone! 5 stars!',
                'meta_data'   => ['verified_purchase' => false],
            ],
            [
                'id'          => 'TEST002',
                'rating'      => 4,
                'review_text' => 'I bought this for my home office setup. After using it for 3 months, I can say it works well for my needs. The build quality is solid, though the cable could be longer. Installation was straightforward with the included instructions. Good value for the price point.',
                'meta_data'   => ['verified_purchase' => true],
            ],
            [
                'id'          => 'TEST003',
                'rating'      => 5,
                'review_text' => 'Great product, works as expected.',
                'meta_data'   => ['verified_purchase' => false],
            ],
            [
                'id'          => 'TEST004',
                'rating'      => 2,
                'review_text' => 'The product arrived damaged and the customer service was unhelpful. The plastic housing cracked within a week of normal use. For the price, I expected better quality control. The functionality works when it works, but reliability is poor.',
                'meta_data'   => ['verified_purchase' => true],
            ],
            [
                'id'          => 'TEST005',
                'rating'      => 5,
                'review_text' => 'Excellent product! Fast shipping! Great seller! Will buy again! Recommend to friends!',
                'meta_data'   => ['verified_purchase' => false],
            ],
        ];
    }

    private function displayResults($result, $sampleReviews): void
    {
        if (!isset($result['detailed_scores'])) {
            $this->error('No detailed scores in result');

            return;
        }

        $scores = $result['detailed_scores'];
        $scoreValues = [];

        foreach ($sampleReviews as $review) {
            $reviewId = $review['id'];
            $scoreData = $scores[$reviewId] ?? null;

            if (!$scoreData) {
                $this->warn("No score found for review {$reviewId}");
                continue;
            }

            // Handle both new object format and legacy numeric format
            if (is_array($scoreData)) {
                $score = $scoreData['score'] ?? 0;
                $label = $scoreData['label'] ?? 'unknown';
                $confidence = $scoreData['confidence'] ?? 0;
                $explanation = $scoreData['explanation'] ?? '';
            } else {
                $score = $scoreData;
                $label = 'legacy';
                $confidence = 0;
                $explanation = '';
            }

            $scoreValues[] = $score;

            $this->info("Review {$reviewId}: Score {$score} | Label: {$label} | Confidence: ".round($confidence, 2));
            $this->info("  Rating: {$review['rating']}/5 | Verified: ".($review['meta_data']['verified_purchase'] ? 'Yes' : 'No'));
            $this->info('  Text: '.substr($review['review_text'], 0, 80).'...');
            if ($explanation) {
                $this->info("  Explanation: {$explanation}");
            }
            $this->info('');
        }

        // Summary statistics
        if (!empty($scoreValues)) {
            $avgScore = array_sum($scoreValues) / count($scoreValues);
            $highScores = array_filter($scoreValues, fn ($s) => $s >= 70);

            $this->info('=== SUMMARY ===');
            $this->info('Average score: '.round($avgScore, 1));
            $this->info('High scores (70+): '.count($highScores).'/'.count($scoreValues).' ('.round(count($highScores) / count($scoreValues) * 100, 1).'%)');

            if (count($highScores) / count($scoreValues) > 0.6) {
                $this->warn('⚠️  Still showing high fake detection rate. Consider further prompt adjustments.');
            } else {
                $this->info('✅ More balanced scoring detected.');
            }
        }
    }
}
