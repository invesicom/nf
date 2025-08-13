<?php

namespace App\Console\Commands;

use App\Services\Amazon\BrightDataScraperService;
use Illuminate\Console\Command;

class CheckBrightDataJob extends Command
{
    protected $signature = 'brightdata:check-job {job_id}';
    protected $description = 'Check the status of a specific BrightData job';

    public function handle()
    {
        $jobId = $this->argument('job_id');
        
        $this->info("🔍 Checking BrightData job: {$jobId}");
        $this->newLine();
        
        $service = app(BrightDataScraperService::class);
        
        // Check overall progress
        $progressData = $service->checkProgress();
        
        $jobFound = false;
        foreach ($progressData as $job) {
            if (isset($job['snapshot_id']) && $job['snapshot_id'] === $jobId) {
                $jobFound = true;
                $this->displayJobInfo($job);
                break;
            }
        }
        
        if (!$jobFound) {
            $this->warn("❌ Job {$jobId} not found in current progress data");
            $this->info("📋 Available jobs:");
            
            if (empty($progressData)) {
                $this->info("   No active jobs found");
            } else {
                foreach ($progressData as $job) {
                    $snapshotId = $job['snapshot_id'] ?? 'unknown';
                    $status = $job['status'] ?? 'unknown';
                    $rows = $job['total_rows'] ?? 0;
                    $this->info("   • {$snapshotId}: {$status} ({$rows} rows)");
                }
            }
        }
        
        $this->newLine();
        $this->info("💡 Tips:");
        $this->info("• If job status is 'running', it may take 15-30 minutes to complete");
        $this->info("• BrightData jobs with 0 rows usually need more time");
        $this->info("• Use: php artisan brightdata:snapshots to see completed jobs");
        
        return 0;
    }
    
    private function displayJobInfo(array $job): void
    {
        $this->info("✅ Job found:");
        $this->table(['Property', 'Value'], [
            ['Snapshot ID', $job['snapshot_id'] ?? 'N/A'],
            ['Status', $job['status'] ?? 'unknown'],
            ['Total Rows', $job['total_rows'] ?? 0],
            ['Created At', $job['created_at'] ?? 'N/A'],
            ['Updated At', $job['updated_at'] ?? 'N/A'],
        ]);
        
        $status = $job['status'] ?? 'unknown';
        $totalRows = $job['total_rows'] ?? 0;
        
        if ($status === 'running') {
            $this->warn("🔄 Job is still running - please wait...");
        } elseif ($status === 'ready' && $totalRows > 0) {
            $this->info("🎉 Job completed successfully with {$totalRows} rows!");
            $this->info("📥 You can download the data using: php artisan brightdata:download {$job['snapshot_id']}");
        } elseif ($status === 'ready' && $totalRows === 0) {
            $this->warn("⚠️  Job completed but returned 0 rows - may need to retry");
        } elseif ($status === 'failed') {
            $this->error("❌ Job failed");
        } else {
            $this->info("ℹ️  Status: {$status}");
        }
    }
}
