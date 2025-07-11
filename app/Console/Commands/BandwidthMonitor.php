<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Services\LoggingService;

class BandwidthMonitor extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'bandwidth:monitor 
                          {--days=7 : Number of days to show}
                          {--reset : Reset bandwidth counters}
                          {--alert-threshold=500 : Daily alert threshold in MB}
                          {--export : Export data to CSV}';

    /**
     * The console command description.
     */
    protected $description = 'Monitor proxy bandwidth usage and provide optimization insights';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('reset')) {
            $this->resetBandwidthCounters();
            return;
        }

        if ($this->option('export')) {
            $this->exportBandwidthData();
            return;
        }

        $this->showBandwidthReport();
    }

    /**
     * Show comprehensive bandwidth usage report.
     */
    private function showBandwidthReport(): void
    {
        $this->info('🌐 Proxy Bandwidth Usage Report');
        $this->line('');

        $days = (int) $this->option('days');
        $threshold = (int) $this->option('alert-threshold');

        // Show daily usage for the specified period
        $this->showDailyUsage($days);
        
        // Show current day details
        $this->showCurrentDayDetails();
        
        // Show optimization insights
        $this->showOptimizationInsights();
        
        // Show bandwidth savings
        $this->showBandwidthSavings();
        
        // Show cost estimates
        $this->showCostEstimates();
    }

    /**
     * Show daily usage for the specified period.
     */
    private function showDailyUsage(int $days): void
    {
        $this->info('📊 Daily Usage Summary');
        $this->line('');

        $totalUsage = 0;
        $daysWithData = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $cacheKey = "bandwidth_usage_{$date}";
            $usage = Cache::get($cacheKey, 0);

            if ($usage > 0) {
                $daysWithData++;
                $totalUsage += $usage;
                
                $status = $usage > (500 * 1024 * 1024) ? '<fg=red>HIGH</>' : '<fg=green>OK</>';
                $this->line(sprintf(
                    '  %s: %s %s',
                    $date,
                    $this->formatBytes($usage),
                    $status
                ));
            }
        }

        $this->line('');
        $this->info("Total Usage ({$daysWithData} days): " . $this->formatBytes($totalUsage));
        
        if ($daysWithData > 0) {
            $avgDaily = $totalUsage / $daysWithData;
            $this->info("Average Daily: " . $this->formatBytes($avgDaily));
        }
        
        $this->line('');
    }

    /**
     * Show current day details.
     */
    private function showCurrentDayDetails(): void
    {
        $this->info('📈 Today\'s Details');
        $this->line('');

        $today = date('Y-m-d');
        $cacheKey = "bandwidth_usage_{$today}";
        $todayUsage = Cache::get($cacheKey, 0);

        $this->line("Current Usage: " . $this->formatBytes($todayUsage));
        
        $dailyLimit = 500 * 1024 * 1024; // 500MB
        $percentUsed = $todayUsage > 0 ? ($todayUsage / $dailyLimit) * 100 : 0;
        $remaining = max(0, $dailyLimit - $todayUsage);

        $this->line("Daily Limit: " . $this->formatBytes($dailyLimit));
        $this->line("Remaining: " . $this->formatBytes($remaining));
        $this->line("Percentage Used: " . round($percentUsed, 1) . '%');

        if ($percentUsed > 80) {
            $this->warn('⚠️  Approaching daily limit!');
        } elseif ($percentUsed > 100) {
            $this->error('🚨 Daily limit exceeded!');
        }

        $this->line('');
    }

    /**
     * Show optimization insights.
     */
    private function showOptimizationInsights(): void
    {
        $this->info('🎯 Optimization Insights');
        $this->line('');

        // Get blocked resources count
        $blockedCount = Cache::get('blocked_resources_count', 0);
        $this->line("Resources Blocked: {$blockedCount}");

        // Get cache hits
        $cacheHits = Cache::get('cache_hits_count', 0);
        $this->line("Cache Hits: {$cacheHits}");

        // Estimated savings
        $estimatedSavings = $blockedCount * 50 * 1024; // Assume 50KB per blocked resource
        $cacheSavings = $cacheHits * 200 * 1024; // Assume 200KB per cache hit
        $totalSavings = $estimatedSavings + $cacheSavings;

        $this->line("Estimated Bandwidth Saved: " . $this->formatBytes($totalSavings));
        $this->line('');

        // Recommendations
        $this->info('💡 Recommendations:');
        $this->line('  • Resource blocking is saving ~' . $this->formatBytes($estimatedSavings));
        $this->line('  • Caching is saving ~' . $this->formatBytes($cacheSavings));
        $this->line('  • Consider increasing cache duration for stable content');
        $this->line('  • Monitor for new resource patterns to block');
        $this->line('');
    }

    /**
     * Show bandwidth savings from optimizations.
     */
    private function showBandwidthSavings(): void
    {
        $this->info('💰 Bandwidth Savings');
        $this->line('');

        $today = date('Y-m-d');
        $actualUsage = Cache::get("bandwidth_usage_{$today}", 0);
        $blockedResources = Cache::get('blocked_resources_count', 0);
        $cacheHits = Cache::get('cache_hits_count', 0);

        // Estimate what usage would have been without optimizations
        $estimatedOriginalUsage = $actualUsage + ($blockedResources * 50 * 1024) + ($cacheHits * 200 * 1024);
        $savings = $estimatedOriginalUsage - $actualUsage;
        $savingsPercent = $estimatedOriginalUsage > 0 ? ($savings / $estimatedOriginalUsage) * 100 : 0;

        $this->line("Without Optimizations: " . $this->formatBytes($estimatedOriginalUsage));
        $this->line("With Optimizations: " . $this->formatBytes($actualUsage));
        $this->line("Savings: " . $this->formatBytes($savings) . " (" . round($savingsPercent, 1) . "%)");
        $this->line('');
    }

    /**
     * Show cost estimates based on proxy pricing.
     */
    private function showCostEstimates(): void
    {
        $this->info('💵 Cost Estimates');
        $this->line('');

        $today = date('Y-m-d');
        $todayUsage = Cache::get("bandwidth_usage_{$today}", 0);
        $monthlyUsage = $todayUsage * 30; // Rough estimate

        // Common proxy pricing (per GB)
        $pricing = [
            'Bright Data' => 15.00,
            'Oxylabs' => 12.00,
            'Smartproxy' => 8.50,
            'ProxyMesh' => 2.00,
        ];

        $this->line("Estimated Monthly Usage: " . $this->formatBytes($monthlyUsage));
        $this->line('');

        foreach ($pricing as $provider => $pricePerGB) {
            $monthlyGB = $monthlyUsage / (1024 * 1024 * 1024);
            $monthlyCost = $monthlyGB * $pricePerGB;
            
            $this->line(sprintf(
                '  %s: $%.2f/month (%.2f GB @ $%.2f/GB)',
                $provider,
                $monthlyCost,
                $monthlyGB,
                $pricePerGB
            ));
        }

        $this->line('');
    }

    /**
     * Reset bandwidth counters.
     */
    private function resetBandwidthCounters(): void
    {
        $this->info('🔄 Resetting bandwidth counters...');

        $days = 30; // Reset last 30 days
        $keysCleared = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $cacheKey = "bandwidth_usage_{$date}";
            
            if (Cache::has($cacheKey)) {
                Cache::forget($cacheKey);
                $keysCleared++;
            }
        }

        // Reset other counters
        Cache::forget('blocked_resources_count');
        Cache::forget('cache_hits_count');

        $this->info("✅ Cleared {$keysCleared} daily counters and optimization metrics");
        
        LoggingService::log('Bandwidth counters reset', [
            'keys_cleared' => $keysCleared,
            'reset_by' => 'console_command'
        ]);
    }

    /**
     * Export bandwidth data to CSV.
     */
    private function exportBandwidthData(): void
    {
        $this->info('📤 Exporting bandwidth data...');

        $filename = 'bandwidth_usage_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = storage_path('app/' . $filename);

        $handle = fopen($filepath, 'w');
        
        // CSV headers
        fputcsv($handle, ['Date', 'Usage (Bytes)', 'Usage (MB)', 'Status']);

        $days = 30; // Export last 30 days
        $totalUsage = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $cacheKey = "bandwidth_usage_{$date}";
            $usage = Cache::get($cacheKey, 0);

            if ($usage > 0) {
                $totalUsage += $usage;
                $usageMB = round($usage / (1024 * 1024), 2);
                $status = $usage > (500 * 1024 * 1024) ? 'HIGH' : 'OK';
                
                fputcsv($handle, [$date, $usage, $usageMB, $status]);
            }
        }

        fclose($handle);

        $this->info("✅ Exported to: {$filepath}");
        $this->info("Total Usage: " . $this->formatBytes($totalUsage));
    }

    /**
     * Format bytes for human-readable display.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
} 