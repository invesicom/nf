<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class CancelBrightDataJobs extends Command
{
    protected $signature = 'brightdata:cancel 
                            {--job-id= : Cancel a specific job by ID}
                            {--older-than=30 : Cancel jobs older than X minutes}
                            {--status=running : Job status to target (running, ready, failed)}
                            {--dry-run : Show what would be canceled without actually canceling}
                            {--force : Skip confirmation prompts}';
    
    protected $description = 'Cancel BrightData jobs to manage rate limits';

    public function handle()
    {
        $apiKey = env('BRIGHTDATA_SCRAPER_API');
        $datasetId = env('BRIGHTDATA_DATASET_ID', 'gd_le8e811kzy4ggddlq');

        if (empty($apiKey)) {
            $this->error('BRIGHTDATA_SCRAPER_API not configured');
            return 1;
        }

        $client = new Client(['timeout' => 30]);
        $jobId = $this->option('job-id');
        $olderThan = (int) $this->option('older-than');
        $status = $this->option('status');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($jobId) {
            return $this->cancelSpecificJob($client, $apiKey, $jobId, $dryRun, $force);
        }

        return $this->cancelJobsByAge($client, $apiKey, $datasetId, $olderThan, $status, $dryRun, $force);
    }

    private function cancelSpecificJob(Client $client, string $apiKey, string $jobId, bool $dryRun, bool $force): int
    {
        $this->info("Canceling specific BrightData job: {$jobId}");

        if ($dryRun) {
            $this->warn("DRY RUN: Would cancel job {$jobId}");
            return 0;
        }

        if (!$force && !$this->confirm("Cancel job {$jobId}?")) {
            $this->info('Canceled by user');
            return 0;
        }

        try {
            $response = $client->post("https://api.brightdata.com/datasets/v3/snapshot/{$jobId}/cancel", [
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode === 200 && trim($body) === 'OK') {
                $this->info("Successfully canceled job {$jobId}");
                return 0;
            } else {
                $this->error("Failed to cancel job {$jobId}: HTTP {$statusCode}, Response: {$body}");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Error canceling job {$jobId}: {$e->getMessage()}");
            return 1;
        }
    }

    private function cancelJobsByAge(Client $client, string $apiKey, string $datasetId, int $olderThan, string $status, bool $dryRun, bool $force): int
    {
        $this->info("Finding BrightData jobs to cancel");
        $this->info("Criteria: {$status} jobs older than {$olderThan} minutes");

        try {
            // Get jobs by status
            $response = $client->get('https://api.brightdata.com/datasets/v3/snapshots', [
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
                'query' => ['dataset_id' => $datasetId, 'status' => $status],
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->error("Failed to fetch jobs: HTTP {$response->getStatusCode()}");
                return 1;
            }

            $jobs = json_decode($response->getBody()->getContents(), true);
            if (!is_array($jobs)) {
                $this->error('Invalid response format');
                return 1;
            }

            $cutoffTime = now()->subMinutes($olderThan);
            $jobsToCancel = [];

            foreach ($jobs as $job) {
                $jobId = $job['id'] ?? null;
                $createdAt = $job['created_at'] ?? null;

                if (!$jobId || !$createdAt) {
                    continue;
                }

                try {
                    $jobCreatedAt = \Carbon\Carbon::parse($createdAt);
                    if ($jobCreatedAt->lt($cutoffTime)) {
                        $jobsToCancel[] = [
                            'id' => $jobId,
                            'created_at' => $createdAt,
                            'age_minutes' => now()->diffInMinutes($jobCreatedAt),
                            'status' => $job['status'] ?? 'unknown',
                            'rows' => $job['total_rows'] ?? 0,
                        ];
                    }
                } catch (\Exception $e) {
                    $this->warn("Could not parse date for job {$jobId}: {$createdAt}");
                }
            }

            if (empty($jobsToCancel)) {
                $this->info("No jobs found matching criteria");
                return 0;
            }

            $this->info("Found " . count($jobsToCancel) . " jobs to cancel:");
            $this->table(
                ['Job ID', 'Age (min)', 'Status', 'Rows', 'Created At'],
                array_map(function ($job) {
                    return [
                        substr($job['id'], 0, 12) . '...',
                        $job['age_minutes'],
                        $job['status'],
                        $job['rows'],
                        $job['created_at'],
                    ];
                }, $jobsToCancel)
            );

            if ($dryRun) {
                $this->warn("DRY RUN: Would cancel " . count($jobsToCancel) . " jobs");
                return 0;
            }

            if (!$force && !$this->confirm("Cancel " . count($jobsToCancel) . " jobs?")) {
                $this->info('Canceled by user');
                return 0;
            }

            $canceled = 0;
            $failed = 0;

            foreach ($jobsToCancel as $job) {
                try {
                    $response = $client->post("https://api.brightdata.com/datasets/v3/snapshot/{$job['id']}/cancel", [
                        'headers' => ['Authorization' => "Bearer {$apiKey}"],
                    ]);

                    $statusCode = $response->getStatusCode();
                    $body = $response->getBody()->getContents();

                    if ($statusCode === 200 && trim($body) === 'OK') {
                        $this->info("Canceled: {$job['id']} (age: {$job['age_minutes']}min)");
                        $canceled++;
                    } else {
                        $this->error("Failed to cancel {$job['id']}: HTTP {$statusCode}, Response: {$body}");
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $this->error("Error canceling {$job['id']}: {$e->getMessage()}");
                    $failed++;
                }

                // Small delay to avoid overwhelming the API
                usleep(500000); // 0.5 seconds
            }

            $this->info("Cancellation complete: {$canceled} canceled, {$failed} failed");
            return $failed > 0 ? 1 : 0;

        } catch (\Exception $e) {
            $this->error("Error fetching jobs: {$e->getMessage()}");
            return 1;
        }
    }
}
