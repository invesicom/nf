<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Command;

class TestTimeoutAlerts extends Command
{
    protected $signature = 'test:timeout-alerts';
    protected $description = 'Test timeout and connectivity alerts';

    public function handle(AlertService $alertService): int
    {
        $this->info('Testing timeout alerts...');

        // Test API timeout alert
        $this->info('Sending API timeout alert...');
        $alertService->apiTimeout(
            'Unwrangle API',
            'B0D8L7K9QR',
            120,
            [
                'attempt'       => 3,
                'max_pages'     => 10,
                'error_details' => 'cURL error 28: Operation timed out after 120 seconds',
            ]
        );

        // Test connectivity issue alert
        $this->info('Sending connectivity issue alert...');
        $alertService->connectivityIssue(
            'Unwrangle API',
            'CONNECTION_TIMEOUT_NO_DATA',
            'cURL error 28: Operation timed out with 0 bytes received',
            [
                'asin'    => 'B0D8L7K9QR',
                'attempt' => 2,
            ]
        );

        $this->info('✅ Timeout alerts sent successfully!');
        $this->info('Check your Pushover app and logs for the alerts.');

        return self::SUCCESS;
    }
}
