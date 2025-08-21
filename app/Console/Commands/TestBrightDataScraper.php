<?php

namespace App\Console\Commands;

use App\Services\Amazon\BrightDataScraperService;
use Illuminate\Console\Command;

class TestBrightDataScraper extends Command
{
    protected $signature = 'test:brightdata-scraper {asin} {--country=us} {--save} {--timeout=1200}';
    protected $description = 'Test BrightData scraper with a specific ASIN';

    public function handle()
    {
        $asin = $this->argument('asin');
        $country = $this->option('country');
        $save = $this->option('save');
        $timeout = (int) $this->option('timeout');

        $this->info('🚀 Testing BrightData Scraper');
        $this->info("ASIN: {$asin}");
        $this->info("Country: {$country}");
        $this->info("Timeout: {$timeout}s (15 minutes)");
        $this->line('');

        // Check API key configuration
        $apiKey = env('BRIGHTDATA_SCRAPER_API');
        if (empty($apiKey)) {
            $this->error('❌ BRIGHTDATA_SCRAPER_API not configured in .env');
            $this->info('💡 Add this to your .env file:');
            $this->info('BRIGHTDATA_SCRAPER_API=f4184bc298077cdc7436cc34bdb351f8bb9a2d6068c4bd4229d68131f9279689');

            return 1;
        }

        $this->info('✅ API Key configured: '.substr($apiKey, 0, 10).'...');
        $this->line('');

        try {
            $service = app()->make(BrightDataScraperService::class);

            $this->info('⏱️  Starting BrightData scraping...');
            $startTime = microtime(true);

            if ($save) {
                $result = $service->fetchReviewsAndSave($asin, $country, "https://www.amazon.com/dp/{$asin}/");
                $reviews = json_decode($result->reviews ?? '[]', true);
                $description = $result->product_description;
                $totalReviews = $result->total_reviews_on_amazon;
                $productTitle = $result->product_title;
            } else {
                $result = $service->fetchReviews($asin, $country);
                $reviews = $result['reviews'];
                $description = $result['description'];
                $totalReviews = $result['total_reviews'];
                $productTitle = $result['product_name'] ?? '';
            }

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->info("✅ BrightData scraping completed in {$duration}s");
            $this->line('');

            // Results summary
            $this->info('📊 RESULTS SUMMARY:');
            $this->info('='.str_repeat('=', 40));
            $this->info('Product Title: '.($productTitle ?: 'Not found'));
            $this->info('Reviews Found: '.count($reviews));
            $this->info('Total Reviews on Amazon: '.($totalReviews ?: 'Unknown'));
            $this->info('Product Description: '.(empty($description) ? 'Not found' : 'Found'));

            if ($save) {
                $this->info('Database Record: Saved');
            }

            $this->line('');

            // Review analysis
            if (count($reviews) > 0) {
                $this->info('🔍 REVIEW ANALYSIS:');
                $this->info('-'.str_repeat('-', 30));

                $ratings = array_column($reviews, 'rating');
                $avgRating = count($ratings) > 0 ? round(array_sum($ratings) / count($ratings), 1) : 0;
                $verifiedCount = count(array_filter($reviews, fn ($r) => $r['verified_purchase'] ?? false));
                $vineCount = count(array_filter($reviews, fn ($r) => $r['vine_review'] ?? false));

                $this->info("Average Rating: {$avgRating}/5");
                $this->info("Verified Purchases: {$verifiedCount}/".count($reviews));
                $this->info("Vine Reviews: {$vineCount}/".count($reviews));

                // Show first few reviews
                $this->line('');
                $this->info('📝 SAMPLE REVIEWS:');
                $this->info('-'.str_repeat('-', 30));

                foreach (array_slice($reviews, 0, 3) as $i => $review) {
                    $num = $i + 1;
                    $rating = $review['rating'] ?? 'N/A';
                    $title = $review['title'] ?? 'No title';
                    $text = substr($review['review_text'] ?? 'No text', 0, 100);
                    $verified = ($review['verified_purchase'] ?? false) ? '✓' : '✗';

                    $this->info("{$num}. [{$rating}⭐] {$verified} {$title}");
                    $this->info('   '.$text.'...');
                    $this->line('');
                }

                // Check 30 review limit
                if (count($reviews) >= 30) {
                    $this->warn('⚠️  Reached ~30 review limit (found '.count($reviews).')');
                    $this->info("💡 This confirms BrightData's ~30 review limitation per product");
                } else {
                    $this->info('ℹ️  Found '.count($reviews).' reviews (under 30 limit)');
                }
            } else {
                $this->error('❌ No reviews found');
                $this->info('💡 Possible reasons:');
                $this->info('   - Invalid ASIN');
                $this->info('   - Product has no reviews');
                $this->info('   - BrightData service issue');
                $this->info('   - API key issue');
            }

            $this->line('');
            $this->info('🏁 Test completed successfully');

            return 0;
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->error("❌ BrightData scraping failed after {$duration}s");
            $this->error('Error: '.$e->getMessage());

            $this->line('');
            $this->info('🔍 TROUBLESHOOTING:');
            $this->info('1. Check API key is correct');
            $this->info('2. Verify ASIN exists on Amazon');
            $this->info('3. Check BrightData service status');
            $this->info('4. Review logs for detailed error info');

            return 1;
        }
    }
}
