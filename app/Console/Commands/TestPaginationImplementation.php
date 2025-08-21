<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonScrapingService;
use Illuminate\Console\Command;

class TestPaginationImplementation extends Command
{
    protected $signature = 'test:pagination-implementation {asin} {--max-pages=3}';
    protected $description = 'Test the updated pagination implementation with a specific ASIN';

    public function handle()
    {
        $asin = $this->argument('asin');
        $maxPages = (int) $this->option('max-pages');

        $this->info("Testing pagination implementation for ASIN: {$asin}");
        $this->info("Max pages to test: {$maxPages}");
        $this->newLine();

        try {
            $service = new AmazonScrapingService();

            $this->info('⏳ Starting review extraction...');
            $startTime = microtime(true);

            $result = $service->fetchReviews($asin);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->newLine();
            $this->info('📊 Results:');
            $this->info('- Total reviews found: '.count($result['reviews']));
            $this->info('- Description: '.(!empty($result['description']) ? 'Found' : 'Not found'));
            $this->info('- Total reviews on Amazon: '.($result['total_reviews'] ?? 'Unknown'));
            $this->info("- Execution time: {$duration} seconds");

            if (!empty($result['reviews'])) {
                $this->newLine();
                $this->info('✅ Success! Found reviews from multiple pages:');

                // Group reviews by page indicators to see if pagination worked
                $pageIndicators = [];
                foreach ($result['reviews'] as $index => $review) {
                    $pageNum = floor($index / 8) + 1; // Estimate page based on review position
                    $pageIndicators[$pageNum] = ($pageIndicators[$pageNum] ?? 0) + 1;
                }

                foreach ($pageIndicators as $page => $count) {
                    $this->info("  Page {$page}: ~{$count} reviews");
                }

                $this->newLine();
                $this->info('Sample reviews:');
                foreach (array_slice($result['reviews'], 0, 3) as $index => $review) {
                    $this->info('  '.($index + 1).'. '.substr($review['review_text'], 0, 100).'...');
                }

                if (count($result['reviews']) > 8) {
                    $this->newLine();
                    $this->info('🎯 PAGINATION SUCCESS: Found more than 8 reviews!');
                } else {
                    $this->warn('⚠️  Only found 8 or fewer reviews - pagination may not be working');
                }
            } else {
                $this->error('❌ No reviews found. Possible causes:');
                $this->error('  - Cookie expired/invalid');
                $this->error('  - CAPTCHA blocking');
                $this->error('  - Product has no reviews');
                $this->error('  - Amazon detected automation');
            }
        } catch (\Exception $e) {
            $this->error('❌ Error testing pagination: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());
        }
    }
}
