<?php

namespace App\Console\Commands;

use App\Services\AlertManager;
use Exception;
use Illuminate\Console\Command;

class TestAlertScenarios extends Command
{
    protected $signature = 'alerts:scenario 
                           {scenario : Scenario to test (brightdata-down, openai-quota, amazon-blocked, recovery-test)}';

    protected $description = 'Test realistic AlertManager scenarios';

    public function handle()
    {
        $scenario = $this->argument('scenario');

        $this->info("🎭 Running AlertManager scenario: {$scenario}");
        $this->newLine();

        match ($scenario) {
            'brightdata-down'    => $this->testBrightDataDown(),
            'openai-quota'       => $this->testOpenAIQuotaExceeded(),
            'amazon-blocked'     => $this->testAmazonBlocked(),
            'recovery-test'      => $this->testRecoveryFlow(),
            'service-comparison' => $this->testServiceComparison(),
            default              => $this->error("Unknown scenario: {$scenario}")
        };
    }

    private function testBrightDataDown()
    {
        $this->line('📡 Simulating BrightData service completely down...');

        $alertManager = app(AlertManager::class);

        // Simulate gradual service degradation
        $failures = [
            ['type' => 'JOB_TRIGGER_FAILED', 'message' => 'BrightData API connection timeout'],
            ['type' => 'JOB_TRIGGER_FAILED', 'message' => 'BrightData authentication failed'],
            ['type' => 'POLLING_TIMEOUT', 'message' => 'Job polling exceeded 300 seconds'],
            ['type' => 'JOB_TRIGGER_FAILED', 'message' => 'BrightData API returning 503'],
            ['type' => 'JOB_TRIGGER_FAILED', 'message' => 'All BrightData datasets unavailable'],
            ['type' => 'JOB_TRIGGER_FAILED', 'message' => 'Complete service outage detected'],
        ];

        foreach ($failures as $i => $failure) {
            $context = [
                'dataset_id'       => 'gd_le8e811kzy4ggddlq',
                'attempt'          => $i + 1,
                'failure_sequence' => 'service_degradation',
                'business_impact'  => 'All review analysis blocked',
            ];

            $alertManager->recordFailure(
                'BrightData API',
                $failure['type'],
                $failure['message'],
                $context,
                new Exception($failure['message'])
            );

            $this->line("  ⚠️  {$failure['message']}");
            sleep(1);
        }

        $this->newLine();
        $this->info('✅ BrightData outage scenario complete - should trigger HIGH_P1 alert');
    }

    private function testOpenAIQuotaExceeded()
    {
        $this->line('🤖 Simulating OpenAI quota exhaustion...');

        $alertManager = app(AlertManager::class);

        // Simulate quota buildup then exhaustion
        for ($i = 0; $i < 4; $i++) {
            $context = [
                'status_code'      => 429,
                'quota_type'       => 'tokens_per_minute',
                'quota_limit'      => 1000000,
                'quota_used'       => 950000 + ($i * 12500),
                'reset_time'       => now()->addMinutes(60),
                'analysis_blocked' => true,
            ];

            $alertManager->recordFailure(
                'OpenAI Service',
                'QUOTA_EXCEEDED',
                'OpenAI quota exceeded - usage at '.number_format($context['quota_used']).' tokens',
                $context,
                new Exception('Rate limit exceeded')
            );

            $this->line('  💸 Quota usage: '.number_format($context['quota_used']).'/1,000,000 tokens');
            sleep(1);
        }

        $this->newLine();
        $this->info('✅ OpenAI quota scenario complete - should trigger MEDIUM_P2 alert (CORE service)');
    }

    private function testAmazonBlocked()
    {
        $this->line('🛡️  Simulating Amazon blocking direct scraping...');

        $alertManager = app(AlertManager::class);

        $failures = [
            ['asin' => 'B0TEST001', 'error' => 'CAPTCHA_DETECTED'],
            ['asin' => 'B0TEST002', 'error' => 'SESSION_EXPIRED'],
            ['asin' => 'B0TEST003', 'error' => 'SCRAPING_FAILED'],
            ['asin' => 'B0TEST004', 'error' => 'SCRAPING_FAILED'],
            ['asin' => 'B0TEST005', 'error' => 'SCRAPING_FAILED'],
        ];

        foreach ($failures as $failure) {
            $context = [
                'asin'                   => $failure['asin'],
                'response_code'          => 403,
                'service_type'           => 'fallback',
                'primary_service_status' => 'BrightData operational',
            ];

            $alertManager->recordFailure(
                'Amazon Direct Scraping',
                $failure['error'],
                "Amazon blocked request for {$failure['asin']}",
                $context,
                new Exception('Amazon anti-bot detection')
            );

            $this->line("  🚫 {$failure['asin']}: {$failure['error']}");
            sleep(1);
        }

        $this->newLine();
        $this->info('✅ Amazon blocking scenario complete - FALLBACK service, should be low priority');
    }

    private function testRecoveryFlow()
    {
        $this->line('🔄 Testing recovery suppression flow...');

        $alertManager = app(AlertManager::class);

        // Step 1: Generate failures to trigger alert
        $this->line('Step 1: Generating failures...');
        for ($i = 0; $i < 6; $i++) {
            $alertManager->recordFailure(
                'Test Recovery Service',
                'CONNECTION_ERROR',
                'Connection failure #'.($i + 1),
                ['step' => 1, 'attempt' => $i + 1]
            );
            $this->line('  ⚠️  Connection failure #'.($i + 1));
        }

        sleep(2);

        // Step 2: Record recovery
        $this->line('Step 2: Recording service recovery...');
        $alertManager->recordRecovery('Test Recovery Service', 'CONNECTION_ERROR');
        $this->line('  ✅ Recovery recorded');

        sleep(1);

        // Step 3: Generate post-recovery failures (should be suppressed)
        $this->line('Step 3: Generating post-recovery failures (should be suppressed)...');
        for ($i = 0; $i < 3; $i++) {
            $alertManager->recordFailure(
                'Test Recovery Service',
                'CONNECTION_ERROR',
                'Post-recovery failure #'.($i + 1),
                ['step' => 3, 'post_recovery' => true, 'attempt' => $i + 1]
            );
            $this->line('  🔇 Post-recovery failure #'.($i + 1).' (suppressed)');
        }

        $this->newLine();
        $this->info('✅ Recovery flow test complete - post-recovery alerts should be suppressed');
    }

    private function testServiceComparison()
    {
        $this->line('⚖️  Testing different service criticality levels...');

        $alertManager = app(AlertManager::class);

        $services = [
            ['name' => 'BrightData Web Scraper', 'criticality' => 'PRIMARY'],
            ['name' => 'OpenAI Service', 'criticality' => 'CORE'],
            ['name' => 'Amazon Direct Scraping', 'criticality' => 'FALLBACK'],
        ];

        foreach ($services as $service) {
            $this->line("Testing {$service['name']} ({$service['criticality']}):");

            // Send 4 failures to each service
            for ($i = 0; $i < 4; $i++) {
                $context = [
                    'service_criticality' => $service['criticality'],
                    'comparison_test'     => true,
                    'failure_number'      => $i + 1,
                ];

                $alertManager->recordFailure(
                    $service['name'],
                    'TEST_ERROR',
                    "Test error for {$service['criticality']} service",
                    $context
                );

                $this->line('  📊 Failure #'.($i + 1).' recorded');
            }

            sleep(1);
            $this->newLine();
        }

        $this->info('✅ Service comparison complete - observe different alert levels by criticality');
    }
}
