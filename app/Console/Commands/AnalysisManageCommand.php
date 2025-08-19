<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AnalysisManageCommand extends Command
{
    protected $signature = 'analysis:manage 
                            {action : The analysis action to perform}
                            {--grades= : Comma-separated list of grades (for reanalyze)}
                            {--limit= : Maximum number of items to process}
                            {--fast : Use fast mode (skip LLM calls)}
                            {--provider= : LLM provider to use}
                            {--parallel : Enable parallel processing}
                            {--chunk-size= : Chunk size for parallel processing}
                            {--dry-run : Show what would be done without executing}
                            {--force : Skip confirmation prompts}
                            {--asin= : Specific ASIN to process}
                            {--timeout= : Timeout in seconds}';

    protected $description = 'Consolidated analysis management command';

    private array $availableActions = [
        'reanalyze' => [
            'command' => 'reanalyze:graded-products',
            'description' => 'Re-analyze products with poor grades',
            'options' => ['grades', 'limit', 'fast', 'provider', 'parallel', 'chunk-size', 'dry-run', 'force']
        ],
        'analyze-patterns' => [
            'command' => 'analyze:fake-detection',
            'description' => 'Analyze fake detection patterns in recent data',
            'options' => ['limit']
        ],
        'process-existing' => [
            'command' => 'process:existing-asin-data',
            'description' => 'Process existing ASIN data',
            'options' => ['limit', 'force']
        ],
        'reprocess-grading' => [
            'command' => 'reprocess:historical-grading',
            'description' => 'Reprocess historical grading data',
            'options' => ['limit', 'dry-run', 'force']
        ],
        'revert-generous' => [
            'command' => 'revert:over-generous-grades',
            'description' => 'Revert over-generous grade adjustments',
            'options' => ['limit', 'dry-run', 'force']
        ],
        'test-scoring' => [
            'command' => 'test:new-scoring',
            'description' => 'Test new scoring system on sample data',
            'options' => ['asin', 'provider']
        ],
        'analyze-transparency' => [
            'command' => 'analyze:transparency-features',
            'description' => 'Analyze transparency features in reviews',
            'options' => ['limit']
        ],
        'analyze-rescraping' => [
            'command' => 'analyze:rescraping-needs',
            'description' => 'Analyze which products need re-scraping',
            'options' => ['limit', 'dry-run']
        ]
    ];

    public function handle(): int
    {
        $action = $this->argument('action');

        // Handle help/list request
        if ($action === 'list' || $action === 'help') {
            return $this->showAvailableActions();
        }

        // Validate action
        if (!isset($this->availableActions[$action])) {
            $this->error("Unknown action: {$action}");
            $this->info("Run 'php artisan analysis:manage list' to see available actions");
            return 1;
        }

        $actionConfig = $this->availableActions[$action];
        
        // Build command arguments from available options
        $commandArgs = [];
        foreach ($actionConfig['options'] as $option) {
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
        $this->info("Executing: {$actionConfig['description']}");
        if (!empty($commandArgs)) {
            $this->info("Options: " . json_encode($commandArgs, JSON_PRETTY_PRINT));
        }
        $this->newLine();

        try {
            $exitCode = Artisan::call($actionConfig['command'], $commandArgs);
            $this->line(Artisan::output());
            
            if ($exitCode === 0) {
                $this->info("Analysis action completed successfully");
            } else {
                $this->error("Analysis action failed with exit code: {$exitCode}");
            }
            
            return $exitCode;
            
        } catch (\Exception $e) {
            $this->error("Failed to execute analysis action: " . $e->getMessage());
            return 1;
        }
    }

    private function showAvailableActions(): int
    {
        $this->info("Available analysis management actions:");
        $this->newLine();

        foreach ($this->availableActions as $action => $config) {
            $this->line("<info>{$action}</info> - {$config['description']}");
            if (!empty($config['options'])) {
                $options = implode(', ', array_map(fn($opt) => "--{$opt}", $config['options']));
                $this->line("  Options: {$options}");
            }
            $this->newLine();
        }

        $this->info("Usage:");
        $this->line("  php artisan analysis:manage <action> [options]");
        $this->newLine();
        
        $this->info("Examples:");
        $this->line("  php artisan analysis:manage reanalyze --grades=F,D --limit=50 --fast");
        $this->line("  php artisan analysis:manage analyze-patterns --limit=100");
        $this->line("  php artisan analysis:manage process-existing --dry-run");
        $this->line("  php artisan analysis:manage test-scoring --asin=B0ABC123 --provider=ollama");

        return 0;
    }
}
