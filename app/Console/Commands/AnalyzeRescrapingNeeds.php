<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use Illuminate\Console\Command;

class AnalyzeRescrapingNeeds extends Command
{
    protected $signature = 'analyze:rescraping-needs {--threshold=20 : Minimum reviews threshold}';
    protected $description = 'Analyze how many products would need re-scraping based on unique review count';

    public function handle()
    {
        $threshold = (int) $this->option('threshold');

        $this->info('🔍 ANALYZING RE-SCRAPING REQUIREMENTS');
        $this->info('===================================');
        $this->newLine();

        // Get all products with reviews
        $allProducts = AsinData::whereNotNull('reviews')
            ->where('reviews', '!=', '[]')
            ->where('reviews', '!=', '{}')
            ->get();

        $this->info("📊 Total products with reviews: {$allProducts->count()}");
        $this->newLine();

        // Analyze review counts after deduplication
        $reviewCounts = [];
        $distribution = [
            '1-5'   => 0,
            '6-10'  => 0,
            '11-15' => 0,
            '16-20' => 0,
            '21-30' => 0,
            '31-50' => 0,
            '50+'   => 0,
        ];

        $this->info('🔄 Analyzing unique review counts (simulating deduplication)...');
        $progressBar = $this->output->createProgressBar($allProducts->count());
        $progressBar->start();

        foreach ($allProducts as $product) {
            $reviews = $product->getReviewsArray();
            if (empty($reviews)) {
                $progressBar->advance();
                continue;
            }

            // Simulate deduplication
            $uniqueTexts = [];
            foreach ($reviews as $review) {
                $text = $review['review_text'] ?? '';
                if (empty($text)) {
                    continue;
                }

                $normalized = $this->normalizeReviewText($text);

                if (!isset($uniqueTexts[$normalized])) {
                    $uniqueTexts[$normalized] = true;
                }
            }

            $uniqueCount = count($uniqueTexts);
            $reviewCounts[] = $uniqueCount;

            // Build distribution
            if ($uniqueCount <= 5) {
                $distribution['1-5']++;
            } elseif ($uniqueCount <= 10) {
                $distribution['6-10']++;
            } elseif ($uniqueCount <= 15) {
                $distribution['11-15']++;
            } elseif ($uniqueCount <= 20) {
                $distribution['16-20']++;
            } elseif ($uniqueCount <= 30) {
                $distribution['21-30']++;
            } elseif ($uniqueCount <= 50) {
                $distribution['31-50']++;
            } else {
                $distribution['50+']++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Re-scraping impact analysis
        $this->info('📈 RE-SCRAPING IMPACT ANALYSIS:');
        $this->info('==============================');

        $thresholds = [5, 10, 15, 20, 25, 30];
        $tableData = [];

        foreach ($thresholds as $t) {
            $needsRescraping = array_filter($reviewCounts, function ($count) use ($t) {
                return $count < $t;
            });

            $percentage = round((count($needsRescraping) / count($reviewCounts)) * 100, 1);
            $tableData[] = [
                "< {$t} reviews",
                count($needsRescraping),
                "{$percentage}%",
                $t === $threshold ? '👈 SELECTED' : '',
            ];
        }

        $this->table(['Threshold', 'Products Needing Re-scraping', 'Percentage', 'Status'], $tableData);
        $this->newLine();

        // Distribution analysis
        $this->info('📊 DISTRIBUTION OF UNIQUE REVIEW COUNTS:');
        $this->info('=======================================');

        $distributionTable = [];
        foreach ($distribution as $range => $count) {
            $percentage = round(($count / count($reviewCounts)) * 100, 1);
            $distributionTable[] = [$range, $count, "{$percentage}%"];
        }

        $this->table(['Review Range', 'Products', 'Percentage'], $distributionTable);
        $this->newLine();

        // Summary for selected threshold
        $needsRescraping = array_filter($reviewCounts, function ($count) use ($threshold) {
            return $count < $threshold;
        });

        $percentage = round((count($needsRescraping) / count($reviewCounts)) * 100, 1);

        $this->warn("🎯 SUMMARY FOR THRESHOLD < {$threshold} REVIEWS:");
        $this->warn('============================================');
        $this->warn('Products needing re-scraping: '.count($needsRescraping)." ({$percentage}%)");
        $this->warn('Products with sufficient data: '.(count($reviewCounts) - count($needsRescraping)).' ('.round(100 - $percentage, 1).'%)');

        if ($percentage > 50) {
            $this->error('⚠️  WARNING: Over 50% of products would need re-scraping!');
            $this->error('   Consider lowering the threshold or implementing gradual re-scraping.');
        } elseif ($percentage > 25) {
            $this->warn('⚠️  MODERATE: 25-50% of products would need re-scraping.');
            $this->warn('   This is manageable but will require significant API calls.');
        } else {
            $this->info('✅ LOW IMPACT: Less than 25% of products need re-scraping.');
            $this->info('   This threshold seems reasonable for implementation.');
        }

        $this->newLine();
        $this->info('💡 Recommendations:');
        if ($percentage <= 15) {
            $this->info("   • Implement automatic re-scraping for products < {$threshold} reviews");
            $this->info('   • Schedule re-scraping in batches to avoid rate limits');
        } elseif ($percentage <= 30) {
            $this->info('   • Consider manual review process for critical products');
            $this->info('   • Implement gradual re-scraping over time');
        } else {
            $this->info('   • Lower the threshold (e.g., 10-15 reviews)');
            $this->info('   • Focus on most important/popular products first');
        }

        return Command::SUCCESS;
    }

    private function normalizeReviewText(string $text): string
    {
        $normalized = trim($text);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^\w\s]/', '', $normalized);

        return $normalized;
    }
}
