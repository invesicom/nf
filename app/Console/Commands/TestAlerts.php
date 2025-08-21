<?php

namespace App\Console\Commands;

use App\Services\AlertManager;
use Exception;
use Illuminate\Console\Command;

class TestAlerts extends Command
{
    protected $signature = 'alerts:test 
                           {--service=Test Service : Service name to test}
                           {--level=low : Alert level (low, medium, high, critical)}
                           {--count=1 : Number of failures to trigger}
                           {--type=API_ERROR : Error type}';

    protected $description = 'Test the AlertManager system with different scenarios';

    public function handle()
    {
        $service = $this->option('service');
        $level = $this->option('level');
        $count = (int) $this->option('count');
        $errorType = $this->option('type');

        $this->info('🧪 Testing AlertManager with:');
        $this->line("   Service: {$service}");
        $this->line("   Level: {$level}");
        $this->line("   Count: {$count}");
        $this->line("   Error Type: {$errorType}");
        $this->newLine();

        $alertManager = app(AlertManager::class);

        // Get the failure count needed for the requested level
        $neededCount = $this->getFailureCountForLevel($level);
        $actualCount = max($count, $neededCount);

        $this->line("📊 Triggering {$actualCount} failures to reach {$level} level...");
        $this->newLine();

        // Generate test failures
        for ($i = 0; $i < $actualCount; $i++) {
            $context = [
                'test_run'       => true,
                'failure_number' => $i + 1,
                'total_failures' => $actualCount,
                'timestamp'      => now()->toISOString(),
                'test_data'      => [
                    'endpoint'   => 'https://api.example.com/test',
                    'user_id'    => 'test_user_123',
                    'request_id' => 'req_'.uniqid(),
                ],
            ];

            $message = 'Test failure #'.($i + 1)." - Simulated {$errorType}";
            $exception = new Exception('Simulated exception for testing');

            try {
                $alertManager->recordFailure($service, $errorType, $message, $context, $exception);

                $this->line('  ✓ Recorded failure #'.($i + 1));

                // Small delay to simulate real-world timing
                usleep(100000); // 0.1 seconds
            } catch (Exception $e) {
                $this->error('  ✗ Failed to record failure #'.($i + 1).': '.$e->getMessage());
            }
        }

        $this->newLine();
        $this->info('🎯 Test complete! Check your notification channels for alerts.');

        // Show error rate information
        $errorRate = $alertManager->getErrorRate($service, $errorType, 15);
        $this->line('📈 Error Rate Summary:');
        $this->line("   Failures in last 15 min: {$errorRate['failures']}");
        $this->line("   Rate per minute: {$errorRate['rate_per_minute']}");
        $this->line('   Timestamps recorded: '.count($errorRate['timestamps']));

        $this->newLine();
        $this->comment('💡 Pro tip: Check your Pushover app or configured notification channels!');
    }

    private function getFailureCountForLevel(string $level): int
    {
        return match ($level) {
            'low'      => 1,      // LOW_P3: 1 failure
            'medium'   => 4,   // MEDIUM_P2: >3 failures in 15 min
            'high'     => 6,     // HIGH_P1: >5 failures in 10 min
            'critical' => 11, // CRITICAL_P0: >10 failures in 5 min
            default    => 1,
        };
    }
}
