<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use App\Services\ReviewAnalysisService;
use Illuminate\Console\Command;

class TestEnhancedAnalysis extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enhanced:test {--asin= : Test specific ASIN} {--recent : Test most recent analysis}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test enhanced review analysis features for GitHub Issue #31';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Testing Enhanced Review Analysis (Issue #31)');
        $this->newLine();

        if ($asin = $this->option('asin')) {
            return $this->testSpecificProduct($asin);
        }

        if ($this->option('recent')) {
            return $this->testRecentProduct();
        }

        $this->info('Usage:');
        $this->line('  --asin=B123456    Test specific ASIN');
        $this->line('  --recent          Test most recent analyzed product');

        return 0;
    }

    private function testSpecificProduct(string $asin)
    {
        $asinData = AsinData::where('asin', $asin)->first();

        if (!$asinData) {
            $this->error("Product with ASIN {$asin} not found");

            return 1;
        }

        return $this->runEnhancedAnalysis($asinData);
    }

    private function testRecentProduct()
    {
        $asinData = AsinData::whereNotNull('detailed_analysis')
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$asinData) {
            $this->warn('No products with detailed analysis found. Run a product analysis first.');

            return 1;
        }

        return $this->runEnhancedAnalysis($asinData);
    }

    private function runEnhancedAnalysis(AsinData $asinData)
    {
        $this->info("🛍️  Testing Enhanced Analysis for: {$asinData->product_title}");
        $this->line("   ASIN: {$asinData->asin} | Current Grade: {$asinData->grade} | Fake: {$asinData->fake_percentage}%");
        $this->newLine();

        try {
            $analysisService = app(ReviewAnalysisService::class);
            $result = $analysisService->performEnhancedAnalysis($asinData);

            if (isset($result['error'])) {
                $this->error($result['error']);

                return 1;
            }

            $this->displayEnhancedResults($result);

            return 0;
        } catch (\Exception $e) {
            $this->error("Enhanced analysis failed: {$e->getMessage()}");

            return 1;
        }
    }

    private function displayEnhancedResults(array $result)
    {
        $analysis = $result['enhanced_analysis_v2'];
        $summary = $analysis['enhanced_summary'];

        $this->info('📊 ENHANCED ANALYSIS RESULTS');
        $this->newLine();

        // Overall Score
        $this->line("🎯 Enhanced Score: {$summary['enhanced_score']} (Grade: {$summary['enhanced_grade']})");
        $this->line("   Original Score: {$summary['original_score']} | Adjustment: {$summary['adjustment_factor']}");
        $this->newLine();

        // Trust Recommendation
        $this->info('🛡️  Trust Recommendation:');
        $this->line("   {$summary['trust_recommendation']}");
        $this->newLine();

        // Keyword Analysis
        $keyword = $analysis['keyword_analysis'];
        $this->info('🔤 Keyword Analysis:');
        $this->line("   Vocabulary Diversity: {$keyword['vocabulary_diversity']['diversity_score']}/10");
        $this->line("   Suspicious Phrases: {$keyword['phrase_patterns']['suspicious_phrases']}");
        $this->line("   Natural Phrases: {$keyword['phrase_patterns']['natural_phrases']}");
        $this->line("   Critical Phrases: {$keyword['phrase_patterns']['critical_phrases']}");
        $this->newLine();

        // Timeline Analysis
        $timeline = $analysis['timeline_analysis'];
        $this->info('📅 Timeline Analysis:');
        $this->line("   Review Spikes: {$timeline['spike_count']}");
        $this->line("   Review Velocity: {$timeline['review_velocity']} reviews/day");
        $this->line("   Manipulation Risk: {$timeline['manipulation_risk']}");
        $this->line("   Date Range: {$timeline['date_range']['first_review']} to {$timeline['date_range']['last_review']}");
        $this->newLine();

        // Vocabulary Analysis
        $vocab = $analysis['vocabulary_analysis'];
        $this->info('📚 Vocabulary Analysis:');
        $this->line("   Diversity Score: {$vocab['diversity_score']}/10");
        $this->line("   Average Word Count: {$vocab['average_word_count']}");
        $this->line("   Vocabulary Health: {$vocab['vocabulary_health']}");
        $this->line("   Similarity Risk: {$vocab['similarity_analysis']['similarity_risk']}");
        $this->newLine();

        // Pros and Cons
        if (!empty($summary['pros'])) {
            $this->info('✅ Positive Indicators:');
            foreach ($summary['pros'] as $pro) {
                $this->line("   • {$pro}");
            }
            $this->newLine();
        }

        if (!empty($summary['cons'])) {
            $this->info('⚠️  Areas of Concern:');
            foreach ($summary['cons'] as $con) {
                $this->line("   • {$con}");
            }
            $this->newLine();
        }

        $this->info('📝 Summary:');
        $lines = explode("\n", $summary['summary_text']);
        foreach ($lines as $line) {
            if (!empty(trim($line))) {
                $this->line("   {$line}");
            }
        }
    }
}
