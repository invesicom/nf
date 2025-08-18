<?php

namespace App\Console\Commands;

use App\Models\AsinData;
use Illuminate\Console\Command;

class AnalyzeFakeDetectionPatterns extends Command
{
    protected $signature = 'analyze:fake-detection {--limit=100 : Number of recent analyses to examine}';
    protected $description = 'Analyze fake review detection patterns to identify overly strict scoring';

    public function handle()
    {
        $limit = $this->option('limit');
        $this->info("Analyzing last {$limit} review analyses for fake detection patterns...\n");

        // Get recent analyses with detailed scoring data
        $analyses = AsinData::whereNotNull('openai_result')
            ->where('fake_percentage', '>', 0)
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get(['asin', 'country', 'fake_percentage', 'openai_result', 'updated_at']);

        if ($analyses->isEmpty()) {
            $this->error('No recent analyses found with scoring data.');
            return 1;
        }

        $this->info("Found {$analyses->count()} analyses to examine\n");

        $totalReviews = 0;
        $totalHighScores = 0;
        $providerStats = [];
        $scoreDistribution = [
            '0-20' => 0,
            '21-40' => 0,
            '41-60' => 0,
            '61-80' => 0,
            '81-100' => 0
        ];
        $suspiciousPatterns = [];

        foreach ($analyses as $analysis) {
            $data = json_decode($analysis->openai_result, true);
            
            if (!$data || !isset($data['detailed_scores'])) {
                continue;
            }

            $provider = $data['analysis_provider'] ?? 'Unknown';
            if (!isset($providerStats[$provider])) {
                $providerStats[$provider] = [
                    'count' => 0,
                    'total_reviews' => 0,
                    'high_scores' => 0,
                    'avg_fake_percentage' => 0
                ];
            }

            $scores = $data['detailed_scores'];
            $reviewCount = count($scores);
            $totalReviews += $reviewCount;
            $providerStats[$provider]['count']++;
            $providerStats[$provider]['total_reviews'] += $reviewCount;
            $providerStats[$provider]['avg_fake_percentage'] += $analysis->fake_percentage;

            // Analyze individual review scores
            foreach ($scores as $reviewId => $scoreData) {
                // Handle both new object format and legacy numeric format
                if (is_array($scoreData) && isset($scoreData['score'])) {
                    $score = $scoreData['score'];
                    $explanation = $scoreData['explanation'] ?? '';
                    $label = $scoreData['label'] ?? '';
                    $confidence = $scoreData['confidence'] ?? 0;
                } elseif (is_numeric($scoreData)) {
                    $score = $scoreData;
                    $explanation = '';
                    $label = '';
                    $confidence = 0;
                } else {
                    continue;
                }
                
                // Count high scores
                if ($score >= 70) {
                    $totalHighScores++;
                    $providerStats[$provider]['high_scores']++;
                    
                    // Collect suspicious patterns for high scores
                    if ($score >= 85) {
                        $suspiciousPatterns[] = [
                            'asin' => $analysis->asin,
                            'score' => $score,
                            'explanation' => substr($explanation, 0, 150),
                            'provider' => $provider
                        ];
                    }
                }

                // Score distribution
                if ($score <= 20) $scoreDistribution['0-20']++;
                elseif ($score <= 40) $scoreDistribution['21-40']++;
                elseif ($score <= 60) $scoreDistribution['41-60']++;
                elseif ($score <= 80) $scoreDistribution['61-80']++;
                else $scoreDistribution['81-100']++;
            }
        }

        // Display results
        $this->displayOverallStats($totalReviews, $totalHighScores, $scoreDistribution);
        $this->displayProviderStats($providerStats);
        $this->displaySuspiciousPatterns($suspiciousPatterns);
        $this->displayRecommendations($totalReviews, $totalHighScores, $providerStats);

        return 0;
    }

    private function displayOverallStats($totalReviews, $totalHighScores, $scoreDistribution)
    {
        $this->info("=== OVERALL STATISTICS ===");
        $this->info("Total reviews analyzed: {$totalReviews}");
        $this->info("High scores (70+): {$totalHighScores} (" . round($totalHighScores/$totalReviews*100, 1) . "%)");
        
        $this->info("\nScore Distribution:");
        foreach ($scoreDistribution as $range => $count) {
            $percentage = round($count/$totalReviews*100, 1);
            $this->info("  {$range}: {$count} ({$percentage}%)");
        }
        $this->info("");
    }

    private function displayProviderStats($providerStats)
    {
        $this->info("=== PROVIDER COMPARISON ===");
        foreach ($providerStats as $provider => $stats) {
            $avgFakePercentage = round($stats['avg_fake_percentage'] / $stats['count'], 1);
            $highScoreRate = round($stats['high_scores'] / $stats['total_reviews'] * 100, 1);
            
            $this->info("Provider: {$provider}");
            $this->info("  Analyses: {$stats['count']}");
            $this->info("  Reviews: {$stats['total_reviews']}");
            $this->info("  High score rate: {$highScoreRate}%");
            $this->info("  Avg fake percentage: {$avgFakePercentage}%");
            $this->info("");
        }
    }

    private function displaySuspiciousPatterns($suspiciousPatterns)
    {
        $this->info("=== SUSPICIOUS HIGH SCORES (85+) ===");
        $this->info("Showing examples that might indicate overly strict detection:\n");
        
        $count = 0;
        foreach ($suspiciousPatterns as $pattern) {
            if ($count >= 10) break; // Limit to 10 examples
            
            $this->info("ASIN: {$pattern['asin']} | Score: {$pattern['score']} | Provider: {$pattern['provider']}");
            $this->info("Explanation: {$pattern['explanation']}...");
            $this->info("");
            $count++;
        }
        
        if (count($suspiciousPatterns) > 10) {
            $remaining = count($suspiciousPatterns) - 10;
            $this->info("... and {$remaining} more high-scoring reviews");
        }
    }

    private function displayRecommendations($totalReviews, $totalHighScores, $providerStats)
    {
        $highScoreRate = round($totalHighScores/$totalReviews*100, 1);
        
        $this->info("=== RECOMMENDATIONS ===");
        
        if ($highScoreRate > 50) {
            $this->warn("⚠️  HIGH SCORE RATE: {$highScoreRate}% of reviews scored 70+ (potentially too strict)");
            $this->info("Consider adjusting prompts to be less aggressive in fake detection.");
        } elseif ($highScoreRate > 30) {
            $this->comment("⚠️  MODERATE HIGH SCORE RATE: {$highScoreRate}% (monitor for accuracy)");
        } else {
            $this->info("✅ Reasonable high score rate: {$highScoreRate}%");
        }

        // Provider-specific recommendations
        foreach ($providerStats as $provider => $stats) {
            $providerHighRate = round($stats['high_scores'] / $stats['total_reviews'] * 100, 1);
            if ($providerHighRate > 60) {
                $this->warn("⚠️  {$provider} has very high fake detection rate: {$providerHighRate}%");
                if (str_contains(strtolower($provider), 'ollama')) {
                    $this->info("   → Consider softening OLLAMA prompt language");
                    $this->info("   → Remove 'AGGRESSIVE' and 'EXTREMELY SUSPICIOUS' language");
                    $this->info("   → Adjust scoring thresholds");
                }
            }
        }

        $this->info("\nNext steps:");
        $this->info("1. Review sample high-scoring reviews manually");
        $this->info("2. Adjust provider prompts if needed");
        $this->info("3. Test with sample data before deploying changes");
    }
}
