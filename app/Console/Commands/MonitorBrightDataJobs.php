<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class MonitorBrightDataJobs extends Command
{
    protected $signature = 'brightdata:monitor {--interval=30} {--max-checks=20}';
    protected $description = 'Monitor BrightData jobs in real-time';

    public function handle()
    {
        $interval = (int) $this->option('interval');
        $maxChecks = (int) $this->option('max-checks');

        $this->info('🔍 Monitoring BrightData Jobs');
        $this->info("Check Interval: {$interval}s");
        $this->info("Max Checks: {$maxChecks}");
        $this->line('='.str_repeat('=', 50));

        $apiKey = env('BRIGHTDATA_SCRAPER_API');
        $datasetId = env('BRIGHTDATA_DATASET_ID', 'gd_le8e811kzy4ggddlq');

        if (empty($apiKey)) {
            $this->error('❌ BRIGHTDATA_SCRAPER_API not configured');

            return 1;
        }

        $client = new Client(['timeout' => 30]);
        $checks = 0;

        while ($checks < $maxChecks) {
            $checks++;
            $this->info("🔄 Check #{$checks} at ".date('H:i:s'));

            try {
                // Check running jobs
                $runningJobs = $this->getSnapshotsByStatus($client, $apiKey, $datasetId, 'running');
                $readyJobs = $this->getSnapshotsByStatus($client, $apiKey, $datasetId, 'ready');

                $this->info('   Running: '.count($runningJobs));
                $this->info('   Ready: '.count($readyJobs));

                // Show details for running jobs
                foreach ($runningJobs as $job) {
                    $this->showJobProgress($client, $apiKey, $job);
                }

                // Show new ready jobs with data
                foreach (array_slice($readyJobs, 0, 3) as $job) {
                    $this->showReadyJobSummary($job);
                }

                $this->line('');

                if ($checks < $maxChecks) {
                    $this->info("⏳ Waiting {$interval}s for next check...");
                    sleep($interval);
                }
            } catch (\Exception $e) {
                $this->error("❌ Error during check #{$checks}: ".$e->getMessage());
                if ($checks < $maxChecks) {
                    sleep($interval);
                }
            }
        }

        $this->info("🏁 Monitoring completed after {$checks} checks");

        return 0;
    }

    private function getSnapshotsByStatus(Client $client, string $apiKey, string $datasetId, string $status): array
    {
        $response = $client->get('https://api.brightdata.com/datasets/v3/snapshots', [
            'headers' => ['Authorization' => "Bearer {$apiKey}"],
            'query'   => ['dataset_id' => $datasetId, 'status' => $status],
        ]);

        if ($response->getStatusCode() !== 200) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true);

        return is_array($data) ? $data : [];
    }

    private function showJobProgress(Client $client, string $apiKey, array $job): void
    {
        $id = $job['id'] ?? 'Unknown';
        $this->info("   🔄 {$id}:");

        try {
            $response = $client->get("https://api.brightdata.com/datasets/v3/snapshot/{$id}", [
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode === 202) {
                $data = json_decode($body, true);
                $message = $data['message'] ?? 'Processing...';
                $this->info("      Status: {$message}");
            } elseif ($statusCode === 200) {
                $data = json_decode($body, true);
                $status = $data['status'] ?? 'unknown';
                $rows = $data['total_rows'] ?? 0;
                $this->info("      Status: {$status} ({$rows} rows)");
            } else {
                $this->info("      Status: HTTP {$statusCode}");
            }
        } catch (\Exception $e) {
            $this->info('      Status: Error - '.$e->getMessage());
        }
    }

    private function showReadyJobSummary(array $job): void
    {
        $id = $job['id'] ?? 'Unknown';
        $rows = $job['total_rows'] ?? 0;
        $created = $job['created_at'] ?? 'Unknown';

        if ($rows > 0) {
            $this->info("   ✅ {$id}: {$rows} rows (Created: {$created})");
        }
    }
}
