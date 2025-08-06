<?php

namespace App\Console\Commands;

use App\Services\LLMServiceManager;
use Illuminate\Console\Command;

class LLMManager extends Command
{
    protected $signature = 'llm:manage 
                            {action : Action to perform (status, switch, test, costs)}
                            {--provider= : Provider name for switch/test actions}
                            {--reviews=50 : Number of reviews for cost estimation}';
    
    protected $description = 'Manage LLM providers and compare costs';

    public function handle()
    {
        $action = $this->argument('action');
        $manager = app(LLMServiceManager::class);

        switch ($action) {
            case 'status':
                $this->showStatus($manager);
                break;
                
            case 'switch':
                $this->switchProvider($manager);
                break;
                
            case 'test':
                $this->testProvider($manager);
                break;
                
            case 'costs':
                $this->compareCosts($manager);
                break;
                
            default:
                $this->error("Unknown action: {$action}");
                $this->info("Available actions: status, switch, test, costs");
                return 1;
        }

        return 0;
    }

    private function showStatus(LLMServiceManager $manager)
    {
        $this->info('🔍 LLM Provider Status');
        $this->line('');

        $metrics = $manager->getProviderMetrics();
        
        $headers = ['Provider', 'Available', 'Health Score', 'Success Rate', 'Avg Response (s)', 'Total Requests'];
        $rows = [];

        foreach ($metrics as $name => $data) {
            $rows[] = [
                $name,
                $data['available'] ? '✅ Yes' : '❌ No',
                $data['health_score'] . '%',
                round($data['success_rate'], 1) . '%',
                round($data['avg_response_time'], 2),
                $data['total_requests']
            ];
        }

        $this->table($headers, $rows);
        
        $optimal = $manager->getOptimalProvider();
        if ($optimal) {
            $this->info("🎯 Optimal Provider: {$optimal->getProviderName()}");
        } else {
            $this->error("❌ No providers available");
        }
    }

    private function switchProvider(LLMServiceManager $manager)
    {
        $provider = $this->option('provider');
        
        if (!$provider) {
            $this->error('Provider name is required. Use --provider=<name>');
            return;
        }

        if ($manager->switchProvider($provider)) {
            $this->info("✅ Switched to provider: {$provider}");
        } else {
            $this->error("❌ Failed to switch to provider: {$provider} (not available)");
        }
    }

    private function testProvider(LLMServiceManager $manager)
    {
        $provider = $this->option('provider');
        
        if (!$provider) {
            $optimal = $manager->getOptimalProvider();
            if (!$optimal) {
                $this->error('No providers available for testing');
                return;
            }
            $provider = $optimal->getProviderName();
        }

        $this->info("🧪 Testing provider: {$provider}");
        $this->line('');

        // Create test reviews
        $testReviews = [
            ['id' => 1, 'text' => 'Amazing product! Fast shipping! Highly recommend!', 'rating' => 5],
            ['id' => 2, 'text' => 'Terrible quality. Broke after one day. Waste of money.', 'rating' => 1],
            ['id' => 3, 'text' => 'Good value for money. Works as expected.', 'rating' => 4],
        ];

        try {
            $startTime = microtime(true);
            $result = $manager->analyzeReviews($testReviews);
            $duration = microtime(true) - $startTime;

            $this->info("✅ Test successful in " . round($duration, 2) . " seconds");
            $this->line('');
            
            if (isset($result['results'])) {
                $this->info('📊 Analysis Results:');
                foreach ($result['results'] as $analysis) {
                    $this->line("  Review {$analysis['id']}: {$analysis['score']}% fake probability");
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Test failed: {$e->getMessage()}");
        }
    }

    private function compareCosts(LLMServiceManager $manager)
    {
        $reviewCount = (int) $this->option('reviews');
        
        $this->info("💰 Cost Comparison for {$reviewCount} reviews");
        $this->line('');

        $comparison = $manager->getCostComparison($reviewCount);
        
        $headers = ['Provider', 'Available', 'Cost per Analysis', 'Monthly Cost (1000 analyses)', 'Health Score'];
        $rows = [];

        foreach ($comparison as $name => $data) {
            if ($data['available']) {
                $monthlyCost = $data['cost'] * 1000;
                $rows[] = [
                    $name,
                    '✅ Available',
                    '$' . number_format($data['cost'], 4),
                    '$' . number_format($monthlyCost, 2),
                    $data['health_score'] . '%'
                ];
            } else {
                $rows[] = [
                    $name,
                    '❌ Unavailable',
                    'N/A',
                    'N/A',
                    '0%'
                ];
            }
        }

        $this->table($headers, $rows);

        // Show potential savings
        $costs = array_filter(array_column($comparison, 'cost'), function($cost) { return $cost !== null; });
        if (count($costs) > 1) {
            $minCost = min($costs);
            $maxCost = max($costs);
            $savings = (($maxCost - $minCost) / $maxCost) * 100;
            
            $this->line('');
            $this->info("💡 Potential Savings: " . round($savings, 1) . "% by choosing the cheapest provider");
            $this->info("💡 Monthly savings on 1000 analyses: $" . number_format(($maxCost - $minCost) * 1000, 2));
        }
    }
} 