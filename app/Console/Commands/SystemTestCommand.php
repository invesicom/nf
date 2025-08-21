<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SystemTestCommand extends Command
{
    protected $signature = 'system:test 
                            {service : The service to test (amazon-scraping, brightdata, alerts, etc.)}
                            {--scenario= : Specific test scenario to run}
                            {--timeout=30 : Timeout for tests in seconds}
                            {--detailed : Show detailed output}';

    protected $description = 'Consolidated testing command for all system services';

    private array $availableServices = [
        'amazon-scraping' => [
            'command'     => 'test:amazon-scraping',
            'description' => 'Test Amazon scraping functionality',
            'scenarios'   => ['basic', 'captcha', 'proxy', 'session'],
        ],
        'brightdata' => [
            'command'     => 'test:brightdata-scraper',
            'description' => 'Test BrightData scraper service',
            'scenarios'   => ['connection', 'scraping', 'snapshots'],
        ],
        'alerts' => [
            'command'     => 'test:alerts',
            'description' => 'Test alert system functionality',
            'scenarios'   => ['basic', 'timeout', 'scenarios'],
        ],
        'alert-scenarios' => [
            'command'     => 'test:alert-scenarios',
            'description' => 'Test specific alert scenarios',
            'scenarios'   => ['connectivity', 'quota', 'timeout'],
        ],
        'ajax-bypass' => [
            'command'     => 'test:ajax-bypass',
            'description' => 'Test AJAX bypass functionality',
            'scenarios'   => ['basic', 'fallback'],
        ],
        'enhanced-analysis' => [
            'command'     => 'test:enhanced-analysis',
            'description' => 'Test enhanced analysis features',
            'scenarios'   => ['llm', 'scoring', 'grading'],
        ],
        'scraping-deduplication' => [
            'command'     => 'test:future-scraping-deduplication',
            'description' => 'Test scraping deduplication logic',
            'scenarios'   => ['basic', 'edge-cases'],
        ],
        'international-urls' => [
            'command'     => 'test:international-urls',
            'description' => 'Test international URL handling',
            'scenarios'   => ['parsing', 'country-detection'],
        ],
        'mailtrain' => [
            'command'     => 'test:mailtrain-connection',
            'description' => 'Test Mailtrain newsletter connection',
            'scenarios'   => ['connection', 'subscription'],
        ],
        'scoring-system' => [
            'command'     => 'test:new-scoring',
            'description' => 'Test new scoring system',
            'scenarios'   => ['ollama', 'openai', 'comparison'],
        ],
        'pagination' => [
            'command'     => 'test:pagination-implementation',
            'description' => 'Test pagination implementation',
            'scenarios'   => ['basic', 'edge-cases'],
        ],
        'timeout-alerts' => [
            'command'     => 'test:timeout-alerts',
            'description' => 'Test timeout alert functionality',
            'scenarios'   => ['short', 'long', 'recovery'],
        ],
    ];

    public function handle(): int
    {
        $service = $this->argument('service');
        $scenario = $this->option('scenario');
        $timeout = (int) $this->option('timeout');
        $detailed = $this->option('detailed');

        // Handle help/list request
        if ($service === 'list' || $service === 'help') {
            return $this->showAvailableServices();
        }

        // Validate service
        if (!isset($this->availableServices[$service])) {
            $this->error("Unknown service: {$service}");
            $this->info("Run 'php artisan system:test list' to see available services");

            return 1;
        }

        $serviceConfig = $this->availableServices[$service];

        // Show service info if no scenario specified and service has multiple scenarios
        if (!$scenario && count($serviceConfig['scenarios']) > 1) {
            $this->info("Available scenarios for {$service}:");
            foreach ($serviceConfig['scenarios'] as $availableScenario) {
                $this->line("  - {$availableScenario}");
            }
            $this->info('Use --scenario=<name> to run a specific scenario');
            $this->newLine();
        }

        // Build command arguments
        $commandArgs = [];
        if ($scenario) {
            $commandArgs['--scenario'] = $scenario;
        }
        if ($timeout !== 30) {
            $commandArgs['--timeout'] = $timeout;
        }
        if ($detailed) {
            $commandArgs['--verbose'] = true;
        }

        // Execute the underlying command
        $this->info("Running {$serviceConfig['description']}...");
        if ($scenario) {
            $this->info("Scenario: {$scenario}");
        }
        $this->newLine();

        try {
            $exitCode = Artisan::call($serviceConfig['command'], $commandArgs);

            if ($detailed || $exitCode !== 0) {
                $this->line(Artisan::output());
            }

            if ($exitCode === 0) {
                $this->info('Test completed successfully');
            } else {
                $this->error("Test failed with exit code: {$exitCode}");
            }

            return $exitCode;
        } catch (\Exception $e) {
            $this->error('Failed to execute test: '.$e->getMessage());

            return 1;
        }
    }

    private function showAvailableServices(): int
    {
        $this->info('Available system test services:');
        $this->newLine();

        foreach ($this->availableServices as $service => $config) {
            $this->line("<info>{$service}</info> - {$config['description']}");
            if (count($config['scenarios']) > 1) {
                $scenarios = implode(', ', $config['scenarios']);
                $this->line("  Scenarios: {$scenarios}");
            }
            $this->newLine();
        }

        $this->info('Usage:');
        $this->line('  php artisan system:test <service> [--scenario=<name>] [--timeout=<seconds>] [--verbose]');
        $this->newLine();

        $this->info('Examples:');
        $this->line('  php artisan system:test amazon-scraping');
        $this->line('  php artisan system:test brightdata --scenario=connection');
        $this->line('  php artisan system:test alerts --detailed --timeout=60');

        return 0;
    }
}
