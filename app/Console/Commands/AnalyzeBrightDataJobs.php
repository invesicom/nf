<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class AnalyzeBrightDataJobs extends Command
{
    protected $signature = 'brightdata:analyze';
    protected $description = 'Analyze current BrightData jobs to understand the 100 job limit issue';

    public function handle()
    {
        $apiKey = env('BRIGHTDATA_SCRAPER_API');
        $datasetId = env('BRIGHTDATA_DATASET_ID', 'gd_le8e811kzy4ggddlq');

        if (empty($apiKey)) {
            $this->error('BRIGHTDATA_SCRAPER_API not configured');
            return 1;
        }

        $this->info('Analyzing BrightData Jobs');
        $this->line('='.str_repeat('=', 50));

        $client = new Client(['timeout' => 30]);

        try {
            // Get all jobs by different statuses
            $statuses = ['running', 'ready', 'failed', 'cancelled'];
            $allJobs = [];
            $statusCounts = [];

            foreach ($statuses as $status) {
                $jobs = $this->getJobsByStatus($client, $apiKey, $datasetId, $status);
                $allJobs[$status] = $jobs;
                $statusCounts[$status] = count($jobs);
                
                $this->info("{$status}: " . count($jobs) . " jobs");
            }

            $this->line('');
            $totalJobs = array_sum($statusCounts);
            $this->info("Total jobs across all statuses: {$totalJobs}");

            // Analyze running jobs in detail
            if (!empty($allJobs['running'])) {
                $this->line('');
                $this->info('Analyzing RUNNING jobs:');
                $this->analyzeRunningJobs($allJobs['running']);
            }

            // Analyze ready jobs that might be empty
            if (!empty($allJobs['ready'])) {
                $this->line('');
                $this->info('Analyzing READY jobs (checking for empty snapshots):');
                $this->analyzeReadyJobs($client, $apiKey, array_slice($allJobs['ready'], 0, 5)); // Check first 5
            }

            // Show recommendations
            $this->line('');
            $this->info('Recommendations:');
            
            if ($statusCounts['running'] >= 90) {
                $this->warn("• You have {$statusCounts['running']} running jobs - very close to the 100 limit!");
                $this->warn("• Consider canceling jobs older than 1-2 hours");
            }
            
            if ($statusCounts['ready'] > 50) {
                $this->info("• You have {$statusCounts['ready']} ready jobs - consider cleaning up old completed jobs");
            }
            
            if ($statusCounts['failed'] > 20) {
                $this->info("• You have {$statusCounts['failed']} failed jobs - these can be cleaned up");
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Analysis failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function getJobsByStatus(Client $client, string $apiKey, string $datasetId, string $status): array
    {
        try {
            $response = $client->get('https://api.brightdata.com/datasets/v3/snapshots', [
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
                'query' => ['dataset_id' => $datasetId, 'status' => $status],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode($response->getBody()->getContents(), true);
            return is_array($data) ? $data : [];
        } catch (\Exception $e) {
            $this->warn("Failed to fetch {$status} jobs: " . $e->getMessage());
            return [];
        }
    }

    private function analyzeRunningJobs(array $runningJobs): void
    {
        $now = now();
        $ageGroups = [
            'under_30min' => 0,
            '30min_to_1hr' => 0,
            '1hr_to_2hr' => 0,
            'over_2hr' => 0,
            'over_6hr' => 0,
        ];

        $oldestJob = null;
        $oldestAge = 0;

        foreach ($runningJobs as $job) {
            $createdAt = $job['created_at'] ?? null;
            if (!$createdAt) continue;

            try {
                $jobCreatedAt = \Carbon\Carbon::parse($createdAt);
                $ageMinutes = $now->diffInMinutes($jobCreatedAt);
                
                if ($ageMinutes > $oldestAge) {
                    $oldestAge = $ageMinutes;
                    $oldestJob = $job;
                }

                if ($ageMinutes < 30) {
                    $ageGroups['under_30min']++;
                } elseif ($ageMinutes < 60) {
                    $ageGroups['30min_to_1hr']++;
                } elseif ($ageMinutes < 120) {
                    $ageGroups['1hr_to_2hr']++;
                } elseif ($ageMinutes < 360) {
                    $ageGroups['over_2hr']++;
                } else {
                    $ageGroups['over_6hr']++;
                }
            } catch (\Exception $e) {
                // Skip jobs with unparseable dates
            }
        }

        $this->info('  Age distribution:');
        $this->info("    Under 30 minutes: {$ageGroups['under_30min']}");
        $this->info("    30min - 1hr: {$ageGroups['30min_to_1hr']}");
        $this->info("    1hr - 2hr: {$ageGroups['1hr_to_2hr']}");
        $this->info("    Over 2hr: {$ageGroups['over_2hr']}");
        $this->info("    Over 6hr: {$ageGroups['over_6hr']}");

        if ($oldestJob) {
            $oldestId = substr($oldestJob['id'] ?? 'unknown', 0, 12) . '...';
            $oldestHours = round($oldestAge / 60, 1);
            $this->warn("  Oldest running job: {$oldestId} ({$oldestHours} hours old)");
            
            if ($oldestAge > 120) { // Over 2 hours
                $this->warn("  ⚠️  Jobs over 2 hours old are likely stuck and should be canceled");
            }
        }
    }

    private function analyzeReadyJobs(Client $client, string $apiKey, array $readyJobs): void
    {
        $emptyJobs = 0;
        $withDataJobs = 0;

        foreach ($readyJobs as $job) {
            $jobId = $job['id'] ?? null;
            if (!$jobId) continue;

            try {
                $response = $client->get("https://api.brightdata.com/datasets/v3/snapshot/{$jobId}", [
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                    'query' => ['format' => 'json'],
                ]);

                $statusCode = $response->getStatusCode();
                
                if ($statusCode === 400) {
                    $body = $response->getBody()->getContents();
                    if (strpos($body, 'Snapshot is empty') !== false) {
                        $emptyJobs++;
                    }
                } elseif ($statusCode === 200) {
                    $withDataJobs++;
                }
            } catch (\Exception $e) {
                // Skip jobs we can't check
            }
        }

        $checkedCount = count($readyJobs);
        $this->info("  Checked {$checkedCount} ready jobs:");
        $this->info("    With data: {$withDataJobs}");
        $this->info("    Empty snapshots: {$emptyJobs}");
        
        if ($emptyJobs > 0) {
            $this->info("  Empty snapshots are normal for products with no reviews");
        }
    }
}
