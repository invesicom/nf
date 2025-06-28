<?php

namespace App\Console\Commands;

use App\Enums\AlertType;
use App\Services\AlertService;
use Illuminate\Console\Command;

class TestAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alerts:test {type?} {--dry-run : Only log alerts, don\'t send notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the alerting system by sending sample alerts';

    /**
     * Execute the console command.
     */
    public function handle(AlertService $alertService): int
    {
        $type = $this->argument('type');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode - alerts will only be logged');
            config(['alerts.development.log_only' => true]);
        }

        if ($type) {
            $this->testSpecificAlert($alertService, $type);
        } else {
            $this->testAllAlerts($alertService);
        }

        return 0;
    }

    /**
     * Test a specific alert type
     */
    private function testSpecificAlert(AlertService $alertService, string $type): void
    {
        $alertType = $this->getAlertTypeFromString($type);
        
        if (!$alertType) {
            $this->error("Invalid alert type: {$type}");
            $this->info('Available types: amazon_session_expired, openai_quota_exceeded, openai_api_error, system_error, database_error, security_alert');
            return;
        }

        $this->info("Testing {$alertType->getDisplayName()} alert...");
        $this->sendTestAlert($alertService, $alertType);
        $this->info("✅ {$alertType->getDisplayName()} alert sent successfully");
    }

    /**
     * Test all alert types
     */
    private function testAllAlerts(AlertService $alertService): void
    {
        $this->info('Testing all alert types...');
        
        $alertTypes = [
            AlertType::AMAZON_SESSION_EXPIRED,
            AlertType::OPENAI_QUOTA_EXCEEDED,
            AlertType::OPENAI_API_ERROR,
            AlertType::SYSTEM_ERROR,
            AlertType::DATABASE_ERROR,
            AlertType::SECURITY_ALERT,
        ];

        foreach ($alertTypes as $alertType) {
            $this->info("Testing {$alertType->getDisplayName()}...");
            $this->sendTestAlert($alertService, $alertType);
            $this->info("✅ {$alertType->getDisplayName()} sent");
            sleep(1); // Small delay between alerts
        }

        $this->info('🎉 All alert tests completed successfully!');
    }

    /**
     * Send a test alert based on the type
     */
    private function sendTestAlert(AlertService $alertService, AlertType $alertType): void
    {
        switch ($alertType) {
            case AlertType::AMAZON_SESSION_EXPIRED:
                $alertService->amazonSessionExpired(
                    'Test: Amazon session has expired and needs re-authentication',
                    ['test_mode' => true, 'asin' => 'B0TEST1234']
                );
                break;

            case AlertType::OPENAI_QUOTA_EXCEEDED:
                $alertService->openaiQuotaExceeded(
                    'Test: You exceeded your current quota, please check your plan and billing details',
                    ['test_mode' => true, 'status_code' => 429]
                );
                break;

            case AlertType::OPENAI_API_ERROR:
                $alertService->openaiApiError(
                    'Test: OpenAI API server error',
                    500,
                    ['test_mode' => true, 'endpoint' => '/chat/completions']
                );
                break;

            case AlertType::SYSTEM_ERROR:
                $exception = new \Exception('Test system error');
                $alertService->systemError(
                    'Test: Critical system error occurred',
                    $exception,
                    ['test_mode' => true, 'component' => 'test_command']
                );
                break;

            case AlertType::DATABASE_ERROR:
                $exception = new \Exception('Test database connection failed');
                $alertService->databaseError(
                    'Test: Database connection failed',
                    $exception,
                    ['test_mode' => true, 'connection' => 'mysql']
                );
                break;

            case AlertType::SECURITY_ALERT:
                $alertService->securityAlert(
                    'Test: Suspicious activity detected - multiple failed login attempts',
                    ['test_mode' => true, 'ip' => '192.168.1.100', 'attempts' => 5]
                );
                break;

            default:
                $alertService->alert(
                    $alertType,
                    "Test alert for {$alertType->getDisplayName()}",
                    ['test_mode' => true]
                );
                break;
        }
    }

    /**
     * Convert string to AlertType enum
     */
    private function getAlertTypeFromString(string $type): ?AlertType
    {
        return match(strtolower($type)) {
            'amazon_session_expired', 'amazon' => AlertType::AMAZON_SESSION_EXPIRED,
            'openai_quota_exceeded', 'quota' => AlertType::OPENAI_QUOTA_EXCEEDED,
            'openai_api_error', 'openai' => AlertType::OPENAI_API_ERROR,
            'system_error', 'system' => AlertType::SYSTEM_ERROR,
            'database_error', 'database' => AlertType::DATABASE_ERROR,
            'security_alert', 'security' => AlertType::SECURITY_ALERT,
            default => null,
        };
    }
}
