<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LoggingService;
use Illuminate\Console\Command;

class FixReviewCountDiscrepancies extends Command
{
    protected $signature = 'fix:review-count-discrepancies 
                            {--dry-run : Show what would be fixed without making changes}
                            {--limit=100 : Limit number of products to process}
                            {--asin= : Fix specific ASIN only}';

    protected $description = 'Fix products where reviews analyzed > total reviews on Amazon by adjusting calculated metrics';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $specificAsin = $this->option('asin');

        $this->info('🔧 FIXING REVIEW COUNT DISCREPANCIES');
        $this->info('===================================');
        $this->newLine();

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Find problematic products
        $query = AsinData::whereNotNull('reviews')
            ->whereNotNull('total_reviews_on_amazon')
            ->where('total_reviews_on_amazon', '>', 0);

        if ($specificAsin) {
            $query->where('asin', $specificAsin);
            $this->info("🎯 Processing specific ASIN: {$specificAsin}");
        } else {
            $query->limit($limit);
            $this->info("📊 Processing up to {$limit} products");
        }

        $products = $query->get();
        $this->info("📦 Found {$products->count()} products to analyze");
        $this->newLine();

        $problematicProducts = [];
        $fixedCount = 0;

        foreach ($products as $product) {
            $reviews = $product->getReviewsArray();
            $actualReviewCount = count($reviews);
            $reportedTotal = $product->total_reviews_on_amazon;

            // Check if we have a discrepancy (analyzed > total on Amazon)
            if ($actualReviewCount > $reportedTotal) {
                $problematicProducts[] = [
                    'product'      => $product,
                    'analyzed'     => $actualReviewCount,
                    'total_amazon' => $reportedTotal,
                    'difference'   => $actualReviewCount - $reportedTotal,
                ];
            }
        }

        if (empty($problematicProducts)) {
            $this->info('✅ No review count discrepancies found!');

            return Command::SUCCESS;
        }

        $this->warn('🚨 Found '.count($problematicProducts).' products with discrepancies:');
        $this->newLine();

        // Display table of problematic products
        $tableData = [];
        foreach ($problematicProducts as $item) {
            $tableData[] = [
                $item['product']->asin,
                $item['total_amazon'],
                $item['analyzed'],
                '+'.$item['difference'],
                $item['product']->fake_percentage.'%',
            ];
        }

        $this->table(['ASIN', 'Amazon Total', 'Analyzed', 'Excess', 'Current Fake %'], $tableData);
        $this->newLine();

        if ($isDryRun) {
            $this->info('💡 Run without --dry-run to apply fixes');

            return Command::SUCCESS;
        }

        // Apply fixes
        $this->info('🔧 Applying fixes...');
        $progressBar = $this->output->createProgressBar(count($problematicProducts));
        $progressBar->start();

        foreach ($problematicProducts as $item) {
            $product = $item['product'];
            $reviews = $product->getReviewsArray();
            $reportedTotal = $product->total_reviews_on_amazon;

            // Deduplicate reviews first
            $uniqueReviews = $this->deduplicateReviews($reviews);
            $uniqueCount = count($uniqueReviews);

            // If still more than reported total, randomly sample down to reported total
            if ($uniqueCount > $reportedTotal) {
                $sampledReviews = array_slice($uniqueReviews, 0, $reportedTotal);
                $uniqueReviews = $sampledReviews;
                $uniqueCount = count($uniqueReviews);
            }

            // Recalculate metrics based on adjusted review set
            $newMetrics = $this->recalculateMetrics($product, $uniqueReviews);

            // Update the product
            $product->reviews = json_encode($uniqueReviews);

            if ($newMetrics) {
                $product->fake_percentage = $newMetrics['fake_percentage'];
                $product->grade = $newMetrics['grade'];
                $product->adjusted_rating = $newMetrics['adjusted_rating'];

                // Update detailed analysis if it exists
                if ($product->detailed_analysis) {
                    $detailedAnalysis = $product->detailed_analysis;
                    $detailedAnalysis['review_count'] = $uniqueCount;
                    $detailedAnalysis['genuine_reviews'] = $newMetrics['genuine_count'];
                    $detailedAnalysis['fake_reviews'] = $newMetrics['fake_count'];
                    $product->detailed_analysis = $detailedAnalysis;
                }
            }

            $product->save();

            // Log the fix
            LoggingService::log('Review count discrepancy fixed', [
                'asin'                    => $product->asin,
                'original_analyzed_count' => $item['analyzed'],
                'amazon_total'            => $reportedTotal,
                'final_count'             => $uniqueCount,
                'duplicates_removed'      => $item['analyzed'] - $uniqueCount,
                'old_fake_percentage'     => $item['product']->fake_percentage,
                'new_fake_percentage'     => $newMetrics['fake_percentage'] ?? null,
                'github_issue'            => 'Review count normalization',
            ]);

            $fixedCount++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Fixed {$fixedCount} products with review count discrepancies");
        $this->info('📊 All products now have: Reviews Analyzed ≤ Total Reviews on Amazon');

        return Command::SUCCESS;
    }

    private function deduplicateReviews(array $reviews): array
    {
        $uniqueReviews = [];
        $seenTexts = [];

        foreach ($reviews as $review) {
            $text = $review['review_text'] ?? '';

            if (empty($text)) {
                $uniqueReviews[] = $review;
                continue;
            }

            $normalizedText = $this->normalizeReviewText($text);

            if (!isset($seenTexts[$normalizedText])) {
                $uniqueReviews[] = $review;
                $seenTexts[$normalizedText] = true;
            }
        }

        return $uniqueReviews;
    }

    private function normalizeReviewText(string $text): string
    {
        $normalized = trim($text);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);

        return $normalized;
    }

    private function recalculateMetrics(AsinData $product, array $reviews): ?array
    {
        if (!$product->openai_result) {
            return null;
        }

        $openaiResult = json_decode($product->openai_result, true);
        if (!isset($openaiResult['detailed_scores'])) {
            return null;
        }

        $reviewCount = count($reviews);
        $fakeCount = 0;
        $totalRating = 0;
        $genuineRatingSum = 0;
        $genuineCount = 0;

        // Recalculate based on available reviews
        foreach ($reviews as $index => $review) {
            $rating = floatval($review['rating'] ?? 0);
            $totalRating += $rating;

            // Check if this review was marked as fake (using index or review text)
            $isFake = false;
            if (isset($openaiResult['detailed_scores'][$index])) {
                $score = $openaiResult['detailed_scores'][$index];
                $isFake = $score >= 85; // Using consistent fake threshold (85+)
            }

            if ($isFake) {
                $fakeCount++;
            } else {
                $genuineCount++;
                $genuineRatingSum += $rating;
            }
        }

        $fakePercentage = $reviewCount > 0 ? round(($fakeCount / $reviewCount) * 100, 1) : 0;
        $averageRating = $reviewCount > 0 ? round($totalRating / $reviewCount, 2) : 0;
        $adjustedRating = $genuineCount > 0 ? round($genuineRatingSum / $genuineCount, 2) : $averageRating;

        // Determine grade based on fake percentage using centralized service
        $grade = \App\Services\GradeCalculationService::calculateGrade($fakePercentage);

        return [
            'fake_percentage' => $fakePercentage,
            'grade'           => $grade,
            'adjusted_rating' => $adjustedRating,
            'fake_count'      => $fakeCount,
            'genuine_count'   => $genuineCount,
        ];
    }
}
