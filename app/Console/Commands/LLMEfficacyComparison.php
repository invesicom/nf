<?php

namespace App\Console\Commands;

use App\Services\LLMServiceManager;
use App\Services\Providers\DeepSeekProvider;
use App\Services\Providers\OpenAIProvider;
use Illuminate\Console\Command;

class LLMEfficacyComparison extends Command
{
    protected $signature = 'llm:compare-efficacy 
                            {--samples=50 : Number of sample reviews to test}
                            {--real-api : Use real API calls instead of mock data}
                            {--save-results : Save results to storage for analysis}';

    protected $description = 'Compare efficacy between OpenAI and DeepSeek providers';

    public function handle()
    {
        $this->info('🔬 LLM Efficacy Comparison Tool');
        $this->line('');

        $sampleCount = (int) $this->option('samples');
        $useRealApi = $this->option('real-api');
        $saveResults = $this->option('save-results');

        if ($useRealApi) {
            $this->warn('⚠️  Using real API calls - this will incur costs!');
            if (!$this->confirm('Continue?')) {
                return 0;
            }
        }

        $testReviews = $this->generateTestDataset($sampleCount);
        $this->info("📝 Generated {$sampleCount} test reviews with known classifications");
        $this->line('');

        $results = [];
        $manager = app(LLMServiceManager::class);

        // Test OpenAI Provider
        $this->info('🤖 Testing OpenAI Provider...');

        try {
            $openaiResults = $this->testProvider('openai', $manager, $testReviews, $useRealApi);
            $results['openai'] = $openaiResults;
            $this->displayProviderResults('OpenAI', $openaiResults);
        } catch (\Exception $e) {
            $this->error("❌ OpenAI test failed: {$e->getMessage()}");
            $results['openai'] = ['error' => $e->getMessage()];
        }

        $this->line('');

        // Test DeepSeek Provider
        $this->info('🧠 Testing DeepSeek Provider...');

        try {
            $deepseekResults = $this->testProvider('deepseek', $manager, $testReviews, $useRealApi);
            $results['deepseek'] = $deepseekResults;
            $this->displayProviderResults('DeepSeek', $deepseekResults);
        } catch (\Exception $e) {
            $this->error("❌ DeepSeek test failed: {$e->getMessage()}");
            $results['deepseek'] = ['error' => $e->getMessage()];
        }

        $this->line('');

        // Compare Results
        if (isset($results['openai']['accuracy']) && isset($results['deepseek']['accuracy'])) {
            $this->displayComparison($results);
        }

        // Save results if requested
        if ($saveResults) {
            $this->saveResultsToFile($results, $testReviews);
        }

        return 0;
    }

    private function testProvider(string $providerName, LLMServiceManager $manager, array $testReviews, bool $useRealApi): array
    {
        if (!$manager->switchProvider($providerName)) {
            throw new \Exception("Failed to switch to {$providerName} provider");
        }

        $startTime = microtime(true);

        if ($useRealApi) {
            $result = $manager->analyzeReviews($testReviews);
        } else {
            // Use mock data for testing
            $result = $this->getMockAnalysisResult($providerName, $testReviews);
        }

        $responseTime = microtime(true) - $startTime;

        $accuracy = $this->calculateAccuracy($result, $testReviews);
        $cost = $this->calculateCost($providerName, count($testReviews));

        return [
            'accuracy'          => $accuracy,
            'response_time'     => round($responseTime, 3),
            'cost_per_analysis' => $cost,
            'total_reviews'     => count($testReviews),
            'provider_name'     => $providerName,
        ];
    }

    private function generateTestDataset(int $count): array
    {
        $reviews = [];
        $templates = [
            // Genuine reviews (20% of dataset)
            'genuine' => [
                'I bought this for {purpose}. {quality_comment} {usage_comment} {minor_issue}',
                "Works well for {usage}. {performance_note} For the price, it's {verdict}.",
                'Had this for {time_period}. {experience} {recommendation}',
            ],
            // Suspicious reviews (30% of dataset)
            'suspicious' => [
                'Great product! {generic_praise} Highly recommend!',
                'Amazing quality! {vague_positive} Five stars!',
                'Excellent! {repetitive_praise} Buy now!',
            ],
            // Fake reviews (50% of dataset)
            'fake' => [
                'AMAZING! BEST PRODUCT EVER!!! {excessive_caps} 100% RECOMMEND!!!',
                'This is the most {superlative} product I have ever purchased in my entire life.',
                'Perfect in every way! {unrealistic_claim} No complaints whatsoever!',
            ],
        ];

        $fillIns = [
            'purpose'           => ['my daughter', 'work', 'school', 'home office'],
            'quality_comment'   => ['Build quality is solid', 'Decent construction', 'Quality seems good'],
            'usage_comment'     => ['Easy to use', 'Setup was straightforward', 'Works as expected'],
            'minor_issue'       => ['Only complaint is the battery life', 'Trackpad can be finicky', 'Could be faster'],
            'usage'             => ['basic tasks', 'office work', 'daily use'],
            'performance_note'  => ['Not the fastest but adequate', 'Does what I need', 'Performance is okay'],
            'verdict'           => ['reasonable', 'acceptable', 'fair'],
            'time_period'       => ['3 months', 'a few weeks', 'about a month'],
            'experience'        => ['Generally satisfied', 'Works fine', 'No major issues'],
            'recommendation'    => ['Would recommend for basic use', 'Good for the price', 'Decent option'],
            'generic_praise'    => ['Fast shipping!', 'Excellent quality!', 'Outstanding value!'],
            'vague_positive'    => ['Exceeded expectations!', 'Perfect for everything!', 'Amazing experience!'],
            'repetitive_praise' => ['Great great great!', 'Perfect perfect!', 'Amazing amazing!'],
            'excessive_caps'    => ['FAST SHIPPING!!!', 'PERFECT QUALITY!!!', 'BEST VALUE!!!'],
            'superlative'       => ['incredible', 'outstanding', 'phenomenal', 'exceptional'],
            'unrealistic_claim' => ['Changed my life!', 'Beyond perfect!', 'Impossibly good!'],
        ];

        $distributions = [
            'genuine'    => 0.2,
            'suspicious' => 0.3,
            'fake'       => 0.5,
        ];

        $id = 1;
        foreach ($distributions as $category => $percentage) {
            $categoryCount = (int) ($count * $percentage);

            for ($i = 0; $i < $categoryCount; $i++) {
                $template = $templates[$category][array_rand($templates[$category])];

                // Fill in template variables
                $text = preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($fillIns) {
                    $key = $matches[1];

                    return $fillIns[$key][array_rand($fillIns[$key])] ?? $matches[0];
                }, $template);

                $reviews[] = [
                    'id'                   => "test_{$id}",
                    'text'                 => $text,
                    'rating'               => $category === 'fake' ? 5 : rand(3, 5),
                    'expected_category'    => $category,
                    'expected_score_range' => $this->getExpectedScoreRange($category),
                ];
                $id++;
            }
        }

        // Shuffle to randomize order
        shuffle($reviews);

        return array_slice($reviews, 0, $count);
    }

    private function getExpectedScoreRange(string $category): array
    {
        return match ($category) {
            'genuine'    => [0, 30],
            'suspicious' => [40, 69],
            'fake'       => [70, 100],
            default      => [0, 100]
        };
    }

    private function getMockAnalysisResult(string $provider, array $testReviews): array
    {
        $results = [];

        foreach ($testReviews as $review) {
            $category = $review['expected_category'];
            $range = $review['expected_score_range'];

            // Add some provider-specific variation and realistic accuracy
            $baseScore = rand($range[0], $range[1]);

            // DeepSeek might be slightly less consistent
            if ($provider === 'deepseek') {
                $variation = rand(-5, 5);
                $baseScore = max(0, min(100, $baseScore + $variation));
            }

            // Add 10-15% error rate for realism
            if (rand(1, 100) <= 12) {
                $baseScore = rand(0, 100); // Random incorrect score
            }

            $results[] = [
                'id'       => $review['id'],
                'score'    => $baseScore,
                'provider' => $provider,
            ];
        }

        return ['results' => $results];
    }

    private function calculateAccuracy(array $result, array $testReviews): array
    {
        $scores = $result['results'] ?? $result['detailed_scores'] ?? [];
        $totalReviews = count($testReviews);
        $correctPredictions = 0;
        $categoryStats = [
            'genuine'    => ['correct' => 0, 'total' => 0],
            'suspicious' => ['correct' => 0, 'total' => 0],
            'fake'       => ['correct' => 0, 'total' => 0],
        ];

        foreach ($testReviews as $review) {
            $reviewId = $review['id'];
            $expectedCategory = $review['expected_category'];
            $expectedRange = $review['expected_score_range'];

            $categoryStats[$expectedCategory]['total']++;

            // Find the score for this review
            $actualScore = null;
            foreach ($scores as $scoreData) {
                if ($scoreData['id'] == $reviewId) {
                    $actualScore = $scoreData['score'];
                    break;
                }
            }

            if ($actualScore !== null) {
                if ($actualScore >= $expectedRange[0] && $actualScore <= $expectedRange[1]) {
                    $correctPredictions++;
                    $categoryStats[$expectedCategory]['correct']++;
                }
            }
        }

        return [
            'overall'             => $totalReviews > 0 ? round($correctPredictions / $totalReviews * 100, 1) : 0,
            'genuine'             => $this->calculateCategoryAccuracy($categoryStats['genuine']),
            'suspicious'          => $this->calculateCategoryAccuracy($categoryStats['suspicious']),
            'fake'                => $this->calculateCategoryAccuracy($categoryStats['fake']),
            'correct_predictions' => $correctPredictions,
            'total_reviews'       => $totalReviews,
        ];
    }

    private function calculateCategoryAccuracy(array $stats): float
    {
        return $stats['total'] > 0 ? round($stats['correct'] / $stats['total'] * 100, 1) : 0;
    }

    private function calculateCost(string $provider, int $reviewCount): float
    {
        return match ($provider) {
            'openai'   => app(OpenAIProvider::class)->getEstimatedCost($reviewCount),
            'deepseek' => app(DeepSeekProvider::class)->getEstimatedCost($reviewCount),
            default    => 0.0
        };
    }

    private function displayProviderResults(string $providerName, array $results): void
    {
        if (isset($results['error'])) {
            $this->error("❌ {$providerName} failed: {$results['error']}");

            return;
        }

        $accuracy = $results['accuracy'];
        $cost = $results['cost_per_analysis'];
        $time = $results['response_time'];

        $this->info("✅ {$providerName} Results:");
        $this->table(['Metric', 'Value'], [
            ['Overall Accuracy', $accuracy['overall'].'%'],
            ['Genuine Detection', $accuracy['genuine'].'%'],
            ['Suspicious Detection', $accuracy['suspicious'].'%'],
            ['Fake Detection', $accuracy['fake'].'%'],
            ['Response Time', $time.'s'],
            ['Cost per Analysis', '$'.number_format($cost, 4)],
            ['Monthly Cost (1000 analyses)', '$'.number_format($cost * 1000, 2)],
        ]);
    }

    private function displayComparison(array $results): void
    {
        $this->info('📊 Head-to-Head Comparison:');

        $openai = $results['openai'];
        $deepseek = $results['deepseek'];

        $headers = ['Metric', 'OpenAI', 'DeepSeek', 'Winner'];
        $rows = [
            [
                'Overall Accuracy',
                $openai['accuracy']['overall'].'%',
                $deepseek['accuracy']['overall'].'%',
                $openai['accuracy']['overall'] > $deepseek['accuracy']['overall'] ? '🏆 OpenAI' : '🏆 DeepSeek',
            ],
            [
                'Response Time',
                $openai['response_time'].'s',
                $deepseek['response_time'].'s',
                $openai['response_time'] < $deepseek['response_time'] ? '🏆 OpenAI' : '🏆 DeepSeek',
            ],
            [
                'Cost per Analysis',
                '$'.number_format($openai['cost_per_analysis'], 4),
                '$'.number_format($deepseek['cost_per_analysis'], 4),
                $openai['cost_per_analysis'] < $deepseek['cost_per_analysis'] ? '🏆 OpenAI' : '🏆 DeepSeek',
            ],
        ];

        $this->table($headers, $rows);

        // Cost savings calculation
        $costSavings = (($openai['cost_per_analysis'] - $deepseek['cost_per_analysis']) / $openai['cost_per_analysis']) * 100;
        $this->line('');
        $this->info('💰 Cost Savings: DeepSeek is '.round($costSavings, 1).'% cheaper');
        $this->info('💡 Monthly Savings (1000 analyses): $'.number_format(($openai['cost_per_analysis'] - $deepseek['cost_per_analysis']) * 1000, 2));
    }

    private function saveResultsToFile(array $results, array $testReviews): void
    {
        $filename = 'llm_efficacy_comparison_'.date('Y-m-d_H-i-s').'.json';
        $filepath = storage_path('app/'.$filename);

        $data = [
            'timestamp'   => now()->toISOString(),
            'test_config' => [
                'sample_count' => count($testReviews),
                'real_api'     => $this->option('real-api'),
            ],
            'results'      => $results,
            'test_reviews' => $testReviews,
        ];

        file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT));
        $this->info("📁 Results saved to: {$filepath}");
    }
}
