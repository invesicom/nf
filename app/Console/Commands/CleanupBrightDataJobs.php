<?php

namespace App\Console\Commands;

use App\Services\Amazon\BrightDataScraperService;
use Illuminate\Console\Command;

class CleanupBrightDataJobs extends Command
{
    protected $signature = 'brightdata:cleanup 
                            {--dry-run : Show what would be cleaned up without actually doing it}
                            {--force : Skip confirmation prompts}';
    
    protected $description = 'Clean up stale BrightData jobs automatically';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('BrightData Job Cleanup');
        $this->info('Configuration:');
        $this->info('  Auto-cancel enabled: ' . (config('amazon.brightdata.auto_cancel_enabled', true) ? 'Yes' : 'No'));
        $this->info('  Stale threshold: ' . config('amazon.brightdata.stale_job_threshold', 60) . ' minutes');
        $this->info('  Max concurrent jobs: ' . config('amazon.brightdata.max_concurrent_jobs', 90));
        $this->line('');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No jobs will actually be canceled');
        }

        try {
            $service = app(BrightDataScraperService::class);
            
            // First, show current job status
            $this->showCurrentStatus($service);
            
            if (!$dryRun && !$force) {
                if (!$this->confirm('Proceed with cleanup?')) {
                    $this->info('Cleanup canceled by user');
                    return 0;
                }
            }
            
            // Perform cleanup
            if (!$dryRun) {
                $result = $service->cancelStaleJobs();
                $this->displayCleanupResults($result);
            } else {
                $this->info('DRY RUN: Use --force to skip confirmation or remove --dry-run to execute');
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Cleanup failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function showCurrentStatus(BrightDataScraperService $service): void
    {
        $this->info('Current BrightData Job Status:');
        
        try {
            $progress = $service->checkProgress();
            
            if (empty($progress)) {
                $this->info('  No active jobs found');
                return;
            }
            
            $statusCounts = [];
            $oldJobs = [];
            $staleThreshold = config('amazon.brightdata.stale_job_threshold', 60);
            $cutoffTime = now()->subMinutes($staleThreshold);
            
            foreach ($progress as $job) {
                $status = $job['status'] ?? 'unknown';
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                
                $createdAt = $job['created_at'] ?? null;
                if ($createdAt) {
                    try {
                        $jobCreatedAt = \Carbon\Carbon::parse($createdAt);
                        if ($jobCreatedAt->lt($cutoffTime)) {
                            $oldJobs[] = [
                                'id' => $job['id'] ?? 'unknown',
                                'status' => $status,
                                'age_minutes' => now()->diffInMinutes($jobCreatedAt),
                                'rows' => $job['total_rows'] ?? 0,
                            ];
                        }
                    } catch (\Exception $e) {
                        // Skip jobs with unparseable dates
                    }
                }
            }
            
            foreach ($statusCounts as $status => $count) {
                $this->info("  {$status}: {$count} jobs");
            }
            
            if (!empty($oldJobs)) {
                $this->line('');
                $this->warn('Stale jobs (older than ' . $staleThreshold . ' minutes):');
                foreach ($oldJobs as $job) {
                    $this->warn("  {$job['id']}: {$job['status']} ({$job['age_minutes']}min, {$job['rows']} rows)");
                }
            } else {
                $this->info('  No stale jobs found');
            }
            
            $this->line('');
        } catch (\Exception $e) {
            $this->error('  Failed to fetch job status: ' . $e->getMessage());
        }
    }

    private function displayCleanupResults(array $result): void
    {
        if (isset($result['error'])) {
            $this->error('Cleanup failed: ' . $result['error']);
            return;
        }
        
        if (isset($result['message'])) {
            $this->info($result['message']);
            return;
        }
        
        $this->info('Cleanup Results:');
        $this->info('  Candidates found: ' . ($result['candidates_found'] ?? 0));
        $this->info('  Successfully canceled: ' . count($result['canceled'] ?? []));
        $this->info('  Failed to cancel: ' . count($result['failed'] ?? []));
        
        if (!empty($result['canceled'])) {
            $this->line('');
            $this->info('Canceled jobs:');
            foreach ($result['canceled'] as $job) {
                $this->info("  {$job['id']} (age: {$job['age_minutes']}min)");
            }
        }
        
        if (!empty($result['failed'])) {
            $this->line('');
            $this->warn('Failed to cancel:');
            foreach ($result['failed'] as $job) {
                $this->warn("  {$job['id']} (age: {$job['age_minutes']}min)");
            }
        }
    }
}
