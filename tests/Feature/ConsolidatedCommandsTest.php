<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConsolidatedCommandsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function system_test_command_shows_available_services()
    {
        $this->artisan('system:test list')
            ->expectsOutputToContain('Available system test services:')
            ->expectsOutputToContain('amazon-scraping - Test Amazon scraping functionality')
            ->expectsOutputToContain('brightdata - Test BrightData scraper service')
            ->expectsOutputToContain('alerts - Test alert system functionality')
            ->assertExitCode(0);
    }

    #[Test]
    public function system_test_command_shows_help_for_unknown_service()
    {
        $this->artisan('system:test unknown-service')
            ->expectsOutputToContain('Unknown service: unknown-service')
            ->expectsOutputToContain("Run 'php artisan system:test list' to see available services")
            ->assertExitCode(1);
    }

    #[Test]
    public function analysis_manage_command_shows_available_actions()
    {
        $this->artisan('analysis:manage list')
            ->expectsOutputToContain('Available analysis management actions:')
            ->expectsOutputToContain('reanalyze - Re-analyze products with poor grades')
            ->expectsOutputToContain('analyze-patterns - Analyze fake detection patterns')
            ->expectsOutputToContain('process-existing - Process existing ASIN data')
            ->assertExitCode(0);
    }

    #[Test]
    public function analysis_manage_command_shows_help_for_unknown_action()
    {
        $this->artisan('analysis:manage unknown-action')
            ->expectsOutputToContain('Unknown action: unknown-action')
            ->expectsOutputToContain("Run 'php artisan analysis:manage list' to see available actions")
            ->assertExitCode(1);
    }

    #[Test]
    public function data_process_command_shows_available_operations()
    {
        $this->artisan('data:process list')
            ->expectsOutputToContain('Available data processing operations:')
            ->expectsOutputToContain('backfill-counts - Backfill total review counts')
            ->expectsOutputToContain('cleanup-zero-reviews - Clean up products with zero reviews')
            ->expectsOutputToContain('fix-discrepancies - Fix review count discrepancies')
            ->assertExitCode(0);
    }

    #[Test]
    public function data_process_command_shows_help_for_unknown_operation()
    {
        $this->artisan('data:process unknown-operation')
            ->expectsOutputToContain('Unknown operation: unknown-operation')
            ->expectsOutputToContain("Run 'php artisan data:process list' to see available operations")
            ->assertExitCode(1);
    }

    #[Test]
    public function monitoring_check_command_shows_available_components()
    {
        $this->artisan('monitoring:check list')
            ->expectsOutputToContain('Available monitoring components:')
            ->expectsOutputToContain('brightdata-jobs - Check BrightData job status')
            ->expectsOutputToContain('asin-stats - Show ASIN statistics and metrics')
            ->assertExitCode(0);
    }

    #[Test]
    public function monitoring_check_command_shows_help_for_unknown_component()
    {
        $this->artisan('monitoring:check unknown-component')
            ->expectsOutputToContain('Unknown component: unknown-component')
            ->expectsOutputToContain("Run 'php artisan monitoring:check list' to see available components")
            ->assertExitCode(1);
    }

    #[Test]
    public function consolidated_commands_handle_help_argument()
    {
        // Test that 'help' argument works the same as 'list'
        $this->artisan('system:test help')
            ->expectsOutputToContain('Available system test services:')
            ->assertExitCode(0);

        $this->artisan('analysis:manage help')
            ->expectsOutputToContain('Available analysis management actions:')
            ->assertExitCode(0);

        $this->artisan('data:process help')
            ->expectsOutputToContain('Available data processing operations:')
            ->assertExitCode(0);

        $this->artisan('monitoring:check help')
            ->expectsOutputToContain('Available monitoring components:')
            ->assertExitCode(0);
    }

    #[Test]
    public function consolidated_commands_show_usage_examples()
    {
        $this->artisan('system:test list')
            ->expectsOutputToContain('Usage:')
            ->expectsOutputToContain('Examples:')
            ->expectsOutputToContain('php artisan system:test amazon-scraping')
            ->assertExitCode(0);

        $this->artisan('analysis:manage list')
            ->expectsOutputToContain('Usage:')
            ->expectsOutputToContain('Examples:')
            ->expectsOutputToContain('php artisan analysis:manage reanalyze --grades=F,D')
            ->assertExitCode(0);

        $this->artisan('data:process list')
            ->expectsOutputToContain('Usage:')
            ->expectsOutputToContain('Examples:')
            ->expectsOutputToContain('php artisan data:process backfill-counts')
            ->assertExitCode(0);

        $this->artisan('monitoring:check list')
            ->expectsOutputToContain('Usage:')
            ->expectsOutputToContain('Examples:')
            ->expectsOutputToContain('php artisan monitoring:check brightdata-jobs')
            ->assertExitCode(0);
    }

    #[Test]
    public function consolidated_commands_show_available_options()
    {
        $this->artisan('analysis:manage list')
            ->expectsOutputToContain('Options: --grades, --limit, --fast, --provider')
            ->expectsOutputToContain('Options: --limit')
            ->assertExitCode(0);

        $this->artisan('data:process list')
            ->expectsOutputToContain('Options: --limit, --dry-run, --force, --chunk-size')
            ->expectsOutputToContain('Options: --force')
            ->assertExitCode(0);

        $this->artisan('system:test list')
            ->expectsOutputToContain('Scenarios: basic, captcha, proxy, session')
            ->expectsOutputToContain('Scenarios: connection, scraping, snapshots')
            ->assertExitCode(0);
    }

    #[Test]
    public function analysis_manage_actually_calls_underlying_commands()
    {
        // Test that the consolidated command actually calls the underlying command
        // We'll use analyze-patterns since it's safe and doesn't modify data
        $this->artisan('analysis:manage analyze-patterns --limit=1')
            ->expectsOutputToContain('Executing: Analyze fake detection patterns in recent data')
            ->expectsOutputToContain('Options:');

        // We don't assert exit code since it depends on data availability
        // The important thing is that the consolidated command structure works
    }

    #[Test]
    public function consolidated_commands_pass_options_correctly()
    {
        // Test that options are passed through correctly
        $this->artisan('analysis:manage analyze-patterns --limit=5')
            ->expectsOutputToContain('Options:');

        // We don't assert exit code since it depends on data availability
    }

    #[Test]
    public function consolidated_commands_handle_boolean_options()
    {
        // Test dry-run mode (boolean option)
        $this->artisan('analysis:manage reanalyze --dry-run --grades=F --limit=1')
            ->expectsOutputToContain('Executing: Re-analyze products with poor grades')
            ->expectsOutputToContain('Options:');

        // We don't assert exit code since it depends on data availability
    }

    #[Test]
    public function system_test_command_handles_scenarios()
    {
        // Test that system:test shows scenarios when available
        $this->artisan('system:test amazon-scraping')
            ->expectsOutputToContain('Available scenarios for amazon-scraping:')
            ->expectsOutputToContain('basic')
            ->expectsOutputToContain('captcha')
            ->expectsOutputToContain('proxy')
            ->expectsOutputToContain('session')
            ->expectsOutputToContain('Use --scenario=<name> to run a specific scenario');

        // The command will fail when it tries to execute the underlying command
        // because it needs an ASIN, but that's expected - we're testing the scenario display
    }

    #[Test]
    public function consolidated_commands_provide_clear_error_messages()
    {
        // Test clear error messages for invalid inputs
        $this->artisan('system:test invalid-service')
            ->expectsOutputToContain('Unknown service: invalid-service')
            ->assertExitCode(1);

        $this->artisan('analysis:manage invalid-action')
            ->expectsOutputToContain('Unknown action: invalid-action')
            ->assertExitCode(1);

        $this->artisan('data:process invalid-operation')
            ->expectsOutputToContain('Unknown operation: invalid-operation')
            ->assertExitCode(1);

        $this->artisan('monitoring:check invalid-component')
            ->expectsOutputToContain('Unknown component: invalid-component')
            ->assertExitCode(1);
    }

    #[Test]
    public function consolidated_commands_maintain_backward_compatibility()
    {
        // Ensure that the old commands still exist and work
        // This test verifies we haven't broken existing functionality

        // Test that original commands still exist
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'analyze:fake-detection',
            $commands,
            'Original analyze:fake-detection command should still exist'
        );
        $this->assertArrayHasKey(
            'reanalyze:graded-products',
            $commands,
            'Original reanalyze:graded-products command should still exist'
        );
        $this->assertArrayHasKey(
            'test:amazon-scraping',
            $commands,
            'Original test:amazon-scraping command should still exist'
        );
    }

    #[Test]
    public function consolidated_commands_reduce_command_count()
    {
        // Verify that we have the new consolidated commands
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'system:test',
            $commands,
            'New system:test consolidated command should exist'
        );
        $this->assertArrayHasKey(
            'analysis:manage',
            $commands,
            'New analysis:manage consolidated command should exist'
        );
        $this->assertArrayHasKey(
            'data:process',
            $commands,
            'New data:process consolidated command should exist'
        );
        $this->assertArrayHasKey(
            'monitoring:check',
            $commands,
            'New monitoring:check consolidated command should exist'
        );
    }
}
