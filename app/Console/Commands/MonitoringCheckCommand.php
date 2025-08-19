<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MonitoringCheckCommand extends Command
{
    protected $signature = 'monitoring:check 
                            {component : The system component to monitor}
                            {--timeout= : Timeout in seconds}
                            {--detailed : Show detailed output}
                            {--format=table : Output format (table, json, csv)}
                            {--watch : Continuously monitor (refresh every 30s)}';

    protected $description = 'Consolidated monitoring command for system components';

    private array $availableComponents = [
        'brightdata-jobs' => [
            'command' => 'check:brightdata-job',
            'description' => 'Check BrightData job status',
            'options' => ['timeout', 'detailed']
        ],
        'brightdata-progress' => [
            'command' => 'check:brightdata-progress',
            'description' => 'Check BrightData job progress',
            'options' => ['timeout', 'detailed']
        ],
        'brightdata-snapshots' => [
            'command' => 'check:brightdata-snapshots',
            'description' => 'Check BrightData snapshots',
            'options' => ['detailed']
        ],
        'brightdata-status' => [
            'command' => 'monitor:brightdata-jobs',
            'description' => 'Monitor BrightData job status',
            'options' => ['format', 'watch']
        ],
        'asin-stats' => [
            'command' => 'show:asin-stats',
            'description' => 'Show ASIN statistics and metrics',
            'options' => ['format', 'verbose']
        ],
        'analysis-workers' => [
            'command' => 'start:analysis-workers',
            'description' => 'Check and start analysis workers',
            'options' => ['detailed']
        ],
        'llm-efficacy' => [
            'command' => 'llm:efficacy-comparison',
            'description' => 'Compare LLM provider efficacy',
            'options' => ['detailed', 'format']
        ]
    ];

    public function handle(): int
    {
        $component = $this->argument('component');
        $watch = $this->option('watch');

        // Handle help/list request
        if ($component === 'list' || $component === 'help') {
            return $this->showAvailableComponents();
        }

        // Validate component
        if (!isset($this->availableComponents[$component])) {
            $this->error("Unknown component: {$component}");
            $this->info("Run 'php artisan monitoring:check list' to see available components");
            return 1;
        }

        if ($watch) {
            return $this->watchComponent($component);
        }

        return $this->checkComponent($component);
    }

    private function checkComponent(string $component): int
    {
        $componentConfig = $this->availableComponents[$component];
        
        // Build command arguments from available options
        $commandArgs = [];
        foreach ($componentConfig['options'] as $option) {
            $value = $this->option($option);
            if ($value !== null) {
                if (is_bool($value) && $value) {
                    $commandArgs["--{$option}"] = true;
                } elseif (!is_bool($value)) {
                    $commandArgs["--{$option}"] = $value;
                }
            }
        }

        // Execute the underlying command
        $this->info("Checking: {$componentConfig['description']}");
        $this->newLine();

        try {
            $exitCode = Artisan::call($componentConfig['command'], $commandArgs);
            $this->line(Artisan::output());
            
            if ($exitCode === 0) {
                $this->info("Monitoring check completed successfully");
            } else {
                $this->error("Monitoring check failed with exit code: {$exitCode}");
            }
            
            return $exitCode;
            
        } catch (\Exception $e) {
            $this->error("Failed to execute monitoring check: " . $e->getMessage());
            return 1;
        }
    }

    private function watchComponent(string $component): int
    {
        $this->info("Starting continuous monitoring of {$component} (press Ctrl+C to stop)");
        $this->newLine();

        while (true) {
            // Clear screen
            $this->line("\033[2J\033[H");
            
            // Show timestamp
            $this->info("Last updated: " . now()->format('Y-m-d H:i:s'));
            $this->newLine();
            
            // Run the check
            $exitCode = $this->checkComponent($component);
            
            if ($exitCode !== 0) {
                $this->error("Check failed - continuing to monitor...");
            }
            
            $this->newLine();
            $this->info("Refreshing in 30 seconds... (Ctrl+C to stop)");
            
            // Wait 30 seconds
            sleep(30);
        }
    }

    private function showAvailableComponents(): int
    {
        $this->info("Available monitoring components:");
        $this->newLine();

        foreach ($this->availableComponents as $component => $config) {
            $this->line("<info>{$component}</info> - {$config['description']}");
            if (!empty($config['options'])) {
                $options = implode(', ', array_map(fn($opt) => "--{$opt}", $config['options']));
                $this->line("  Options: {$options}");
            }
            $this->newLine();
        }

        $this->info("Usage:");
        $this->line("  php artisan monitoring:check <component> [options]");
        $this->newLine();
        
        $this->info("Examples:");
        $this->line("  php artisan monitoring:check brightdata-jobs --detailed");
        $this->line("  php artisan monitoring:check asin-stats --format=json");
        $this->line("  php artisan monitoring:check brightdata-status --watch");

        return 0;
    }
}
