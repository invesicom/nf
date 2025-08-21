<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\LLMServiceManager;
use Illuminate\Console\Command;

class AnalyzeTransparencyFeatures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transparency:analyze {--asin= : Specific ASIN to analyze} {--recent=5 : Number of recent analyses to show} {--demo : Run with sample data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze and demonstrate enhanced transparency features for fake review detection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Review Analysis Transparency Features');
        $this->newLine();

        if ($this->option('demo')) {
            return $this->runDemo();
        }

        if ($asin = $this->option('asin')) {
            return $this->analyzeSpecificProduct($asin);
        }

        return $this->showRecentAnalyses();
    }

    private function runDemo()
    {
        $this->info('🎯 Demo: Enhanced Transparency Features');
        $this->newLine();

        // Sample fake reviews for demonstration
        $sampleReviews = [
            [
                'id'        => 'demo1',
                'rating'    => 5,
                'text'      => 'Amazing product! Highly recommend to everyone. Five stars!!!',
                'meta_data' => ['verified_purchase' => false],
            ],
            [
                'id'        => 'demo2',
                'rating'    => 5,
                'text'      => 'This product changed my life. Best purchase ever. Quality is outstanding and delivery was fast. Perfect in every way.',
                'meta_data' => ['verified_purchase' => false],
            ],
            [
                'id'        => 'demo3',
                'rating'    => 4,
                'text'      => 'Good quality for the price. Had some minor issues with setup but customer service helped. Would buy again for my family.',
                'meta_data' => ['verified_purchase' => true],
            ],
        ];

        $this->info('📝 Sample Reviews:');
        foreach ($sampleReviews as $i => $review) {
            $verified = $review['meta_data']['verified_purchase'] ? '✅ Verified' : '❌ Unverified';
            $this->line("  {$review['rating']}/5 ⭐ {$verified}");
            $this->line("  \"{$review['text']}\"");
            $this->newLine();
        }

        try {
            $llmManager = app(LLMServiceManager::class);
            $result = $llmManager->analyzeReviews($sampleReviews);

            $this->info('🤖 AI Analysis Results:');
            $this->line('   Raw result keys: '.implode(', ', array_keys($result)));
            $this->newLine();

            if (isset($result['detailed_scores'])) {
                $this->line('   Found '.count($result['detailed_scores']).' detailed scores');
                foreach ($result['detailed_scores'] as $reviewId => $score) {
                    $riskLevel = $score >= 70 ? '🚨 HIGH RISK' : ($score >= 40 ? '⚠️  MEDIUM RISK' : '✅ LOW RISK');

                    $this->line("Review {$reviewId}: {$riskLevel} ({$score}% fake risk)");

                    // Generate explanation based on score
                    if ($score >= 70) {
                        $explanation = 'High fake risk: Multiple suspicious indicators detected';
                    } elseif ($score >= 40) {
                        $explanation = 'Medium fake risk: Some concerning patterns found';
                    } elseif ($score >= 20) {
                        $explanation = 'Low fake risk: Minor inconsistencies noted';
                    } else {
                        $explanation = 'Appears genuine: Natural language and specific details';
                    }

                    $this->line("  🔍 {$explanation}");
                    $this->newLine();
                }
            }

            $this->info('✨ This demonstrates how our enhanced analysis provides detailed explanations for each review,');
            $this->info('   helping users understand exactly why reviews were flagged as potentially fake.');
        } catch (\Exception $e) {
            $this->error("Demo failed: {$e->getMessage()}");

            return 1;
        }

        return 0;
    }

    private function analyzeSpecificProduct(string $asin)
    {
        $this->info("🔍 Analyzing transparency features for ASIN: {$asin}");
        $this->newLine();

        $asinData = AsinData::where('asin', $asin)->first();

        if (!$asinData) {
            $this->error("Product with ASIN {$asin} not found in database");

            return 1;
        }

        if (!$asinData->fake_review_examples) {
            $this->warn('No fake review examples found for this product. Run a new analysis to generate transparency data.');

            return 1;
        }

        $this->displayProductTransparency($asinData);

        return 0;
    }

    private function showRecentAnalyses()
    {
        $recent = $this->option('recent');
        $this->info("📊 Recent Analyses with Transparency Features (last {$recent})");
        $this->newLine();

        $recentAnalyses = AsinData::whereNotNull('fake_review_examples')
            ->whereNotNull('detailed_analysis')
            ->orderBy('updated_at', 'desc')
            ->take($recent)
            ->get();

        if ($recentAnalyses->isEmpty()) {
            $this->warn('No recent analyses with transparency features found.');
            $this->info('Run some product analyses with the enhanced system to see transparency data.');

            return 1;
        }

        foreach ($recentAnalyses as $asinData) {
            $this->displayProductTransparency($asinData);
            $this->newLine();
        }

        return 0;
    }

    private function displayProductTransparency(AsinData $asinData)
    {
        $this->info("🛍️  Product: {$asinData->product_title}");
        $this->line("   ASIN: {$asinData->asin} | Grade: {$asinData->grade} | Fake: {$asinData->fake_percentage}%");
        $this->newLine();

        $examples = $asinData->fake_review_examples ?? [];

        if (empty($examples)) {
            $this->warn('   No fake review examples available');

            return;
        }

        $this->info('🚩 Fake Review Examples ('.count($examples).' found):');
        foreach ($examples as $i => $example) {
            $score = $example['score'];
            $confidence = $example['confidence'];
            $verified = $example['verified_purchase'] ? '✅' : '❌';

            $this->line('   Example '.($i + 1).": {$score}% fake risk ({$confidence} confidence) {$verified}");
            $this->line("   📝 \"{$example['review_text']}\"");
            $this->line("   🔍 {$example['explanation']}");

            if (!empty($example['red_flags'])) {
                $this->line('   🚩 Flags: '.implode(', ', $example['red_flags']));
            }

            $this->line("   🤖 {$example['provider']} ({$example['model']})");
            $this->newLine();
        }
    }
}
