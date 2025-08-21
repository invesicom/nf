<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonAjaxReviewService;
use App\Services\Amazon\AmazonScrapingService;
use Illuminate\Console\Command;

class TestAjaxBypass extends Command
{
    protected $signature = 'test:ajax-bypass {asin} {--compare : Compare AJAX vs direct scraping}';
    protected $description = 'Test the AJAX bypass service against Amazon anti-bot protections';

    public function handle()
    {
        $asin = $this->argument('asin');
        $compare = $this->option('compare');

        $this->info('🔍 Testing AJAX Bypass Service');
        $this->info("ASIN: {$asin}");
        $this->info('Compare with direct scraping: '.($compare ? 'Yes' : 'No'));
        $this->newLine();

        // Test AJAX bypass service
        $this->info('📋 Testing AJAX Bypass Method');
        $this->info('==============================');

        $ajaxResults = $this->testAjaxMethod($asin);

        if ($compare) {
            $this->newLine();
            $this->info('📋 Testing Direct Scraping Method (for comparison)');
            $this->info('==================================================');

            $directResults = $this->testDirectMethod($asin);

            $this->newLine();
            $this->info('📊 COMPARISON RESULTS');
            $this->info('=====================');
            $this->compareResults($ajaxResults, $directResults);
        }

        $this->newLine();
        $this->info('🎯 AJAX BYPASS ASSESSMENT');
        $this->info('=========================');
        $this->assessAjaxBypass($ajaxResults);
    }

    private function testAjaxMethod(string $asin): array
    {
        $startTime = microtime(true);

        try {
            $service = new AmazonAjaxReviewService();
            $result = $service->fetchReviews($asin);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $reviewCount = count($result['reviews']);
            $hasDescription = !empty($result['description']);
            $totalReviews = $result['total_reviews'] ?? 0;

            $this->info("✅ AJAX method completed in {$duration} seconds");
            $this->info("   Reviews extracted: {$reviewCount}");
            $this->info('   Product description: '.($hasDescription ? 'Found' : 'Not found'));
            $this->info("   Total reviews on Amazon: {$totalReviews}");

            if ($reviewCount > 8) {
                $this->info('   🎯 SUCCESS: Bypassed 8-review limitation!');
            } elseif ($reviewCount > 0) {
                $this->warn("   ⚠️  Found reviews but still limited to {$reviewCount}");
            } else {
                $this->error('   ❌ No reviews found');
            }

            return [
                'success'         => $reviewCount > 0,
                'review_count'    => $reviewCount,
                'duration'        => $duration,
                'bypassed_limit'  => $reviewCount > 8,
                'has_description' => $hasDescription,
                'total_reviews'   => $totalReviews,
                'error'           => null,
            ];
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->error("❌ AJAX method failed after {$duration} seconds");
            $this->error('   Error: '.$e->getMessage());

            return [
                'success'         => false,
                'review_count'    => 0,
                'duration'        => $duration,
                'bypassed_limit'  => false,
                'has_description' => false,
                'total_reviews'   => 0,
                'error'           => $e->getMessage(),
            ];
        }
    }

    private function testDirectMethod(string $asin): array
    {
        $startTime = microtime(true);

        try {
            $service = new AmazonScrapingService();
            $result = $service->fetchReviews($asin);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $reviewCount = count($result['reviews']);
            $hasDescription = !empty($result['description']);
            $totalReviews = $result['total_reviews'] ?? 0;

            $this->info("✅ Direct method completed in {$duration} seconds");
            $this->info("   Reviews extracted: {$reviewCount}");
            $this->info('   Product description: '.($hasDescription ? 'Found' : 'Not found'));
            $this->info("   Total reviews on Amazon: {$totalReviews}");

            if ($reviewCount > 8) {
                $this->info('   🎯 SUCCESS: Bypassed 8-review limitation!');
            } elseif ($reviewCount > 0) {
                $this->warn("   ⚠️  Found reviews but still limited to {$reviewCount}");
            } else {
                $this->error('   ❌ No reviews found');
            }

            return [
                'success'         => $reviewCount > 0,
                'review_count'    => $reviewCount,
                'duration'        => $duration,
                'bypassed_limit'  => $reviewCount > 8,
                'has_description' => $hasDescription,
                'total_reviews'   => $totalReviews,
                'error'           => null,
            ];
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->error("❌ Direct method failed after {$duration} seconds");
            $this->error('   Error: '.$e->getMessage());

            return [
                'success'         => false,
                'review_count'    => 0,
                'duration'        => $duration,
                'bypassed_limit'  => false,
                'has_description' => false,
                'total_reviews'   => 0,
                'error'           => $e->getMessage(),
            ];
        }
    }

    private function compareResults(array $ajax, array $direct): void
    {
        $this->info('Method Comparison:');
        $this->table(['Metric', 'AJAX Bypass', 'Direct Scraping'], [
            ['Success', $ajax['success'] ? '✅ Yes' : '❌ No', $direct['success'] ? '✅ Yes' : '❌ No'],
            ['Reviews Found', $ajax['review_count'], $direct['review_count']],
            ['Execution Time', $ajax['duration'].'s', $direct['duration'].'s'],
            ['Bypassed 8-review limit', $ajax['bypassed_limit'] ? '✅ Yes' : '❌ No', $direct['bypassed_limit'] ? '✅ Yes' : '❌ No'],
            ['Product Description', $ajax['has_description'] ? '✅ Found' : '❌ Missing', $direct['has_description'] ? '✅ Found' : '❌ Missing'],
            ['Total Reviews (Amazon)', $ajax['total_reviews'], $direct['total_reviews']],
        ]);

        // Analysis
        if ($ajax['success'] && !$direct['success']) {
            $this->info('🎯 AJAX BYPASS ADVANTAGE: AJAX succeeded where direct scraping failed!');
        } elseif (!$ajax['success'] && $direct['success']) {
            $this->warn('⚠️  DIRECT SCRAPING ADVANTAGE: Direct method succeeded where AJAX failed');
        } elseif ($ajax['review_count'] > $direct['review_count']) {
            $difference = $ajax['review_count'] - $direct['review_count'];
            $this->info("🎯 AJAX BYPASS ADVANTAGE: Found {$difference} more reviews than direct scraping");
        } elseif ($direct['review_count'] > $ajax['review_count']) {
            $difference = $direct['review_count'] - $ajax['review_count'];
            $this->warn("⚠️  DIRECT SCRAPING ADVANTAGE: Found {$difference} more reviews than AJAX");
        } else {
            $this->info('📊 EQUIVALENT RESULTS: Both methods performed similarly');
        }
    }

    private function assessAjaxBypass(array $results): void
    {
        if (!$results['success']) {
            $this->error('❌ AJAX BYPASS FAILED');
            $this->error('Possible causes:');
            $this->error('  - Cookie expired/invalid');
            $this->error('  - Amazon detected automation');
            $this->error('  - CSRF token extraction failed');
            $this->error('  - AJAX endpoint blocked');
            $this->error('  - Session bootstrap failed');

            return;
        }

        if ($results['bypassed_limit']) {
            $this->info('🎯 AJAX BYPASS SUCCESSFUL!');
            $this->info('✅ Successfully bypassed the 8-review limitation');
            $this->info("✅ Extracted {$results['review_count']} reviews using AJAX endpoints");
            $this->info('✅ Amazon anti-bot protections circumvented');

            $this->newLine();
            $this->info('🚀 PRODUCTION RECOMMENDATION:');
            $this->info('Set AMAZON_REVIEW_SERVICE=ajax in .env to use this method');
        } elseif ($results['review_count'] > 0) {
            $this->warn('⚠️  PARTIAL SUCCESS');
            $this->warn("Found {$results['review_count']} reviews but didn't bypass pagination limit");
            $this->warn('This suggests:');
            $this->warn('  - AJAX method works but pagination parameters need refinement');
            $this->warn('  - Session might have limited privileges');
            $this->warn('  - AJAX endpoint responds but with limited data');
        } else {
            $this->error('❌ AJAX BYPASS INEFFECTIVE');
            $this->error('No reviews found - method needs debugging');
        }

        $this->newLine();
        $this->info('📋 Next Steps:');
        if ($results['bypassed_limit']) {
            $this->info('  1. Update .env: AMAZON_REVIEW_SERVICE=ajax');
            $this->info('  2. Monitor performance in production');
            $this->info('  3. Track success rates vs direct scraping');
        } else {
            $this->info('  1. Check logs for detailed error information');
            $this->info('  2. Verify Amazon cookie validity');
            $this->info('  3. Test with different ASINs');
            $this->info('  4. Consider refining AJAX parameters');
        }
    }
}
