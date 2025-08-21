<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class CheckBrightDataSnapshots extends Command
{
    protected $signature = 'brightdata:snapshots {--status=all} {--limit=10}';
    protected $description = 'Check BrightData snapshots list';

    public function handle()
    {
        $status = $this->option('status');
        $limit = (int) $this->option('limit');

        $this->info('🔍 Checking BrightData Snapshots');
        $this->info('Status Filter: '.($status === 'all' ? 'All' : $status));
        $this->info("Limit: {$limit}");
        $this->line('='.str_repeat('=', 50));

        $apiKey = env('BRIGHTDATA_SCRAPER_API');
        $datasetId = env('BRIGHTDATA_DATASET_ID', 'gd_le8e811kzy4ggddlq');

        if (empty($apiKey)) {
            $this->error('❌ BRIGHTDATA_SCRAPER_API not configured');

            return 1;
        }

        try {
            $client = new Client(['timeout' => 30]);

            // Build query parameters
            $queryParams = [
                'dataset_id' => $datasetId,
            ];

            if ($status !== 'all') {
                $queryParams['status'] = $status;
            }

            $response = $client->get('https://api.brightdata.com/datasets/v3/snapshots', [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                ],
                'query' => $queryParams,
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode !== 200) {
                $this->error("❌ API request failed with status {$statusCode}");
                $this->error('Response: '.substr($body, 0, 500));

                return 1;
            }

            $data = json_decode($body, true);

            if (!is_array($data)) {
                $this->error('❌ Invalid response format');

                return 1;
            }

            if (empty($data)) {
                $this->info('✅ No snapshots found');

                return 0;
            }

            $this->info('📊 Found '.count($data).' snapshots');
            $this->line('');

            $displayed = 0;
            foreach ($data as $snapshot) {
                if ($displayed >= $limit) {
                    break;
                }

                $this->displaySnapshot($snapshot, $displayed + 1);
                $displayed++;
            }

            if (count($data) > $limit) {
                $remaining = count($data) - $limit;
                $this->info("... and {$remaining} more snapshots");
            }

            $this->line('');
            $this->info('🏁 Snapshots check completed');

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to check snapshots: '.$e->getMessage());

            return 1;
        }
    }

    private function displaySnapshot(array $snapshot, int $number): void
    {
        $id = $snapshot['id'] ?? 'Unknown';
        $status = $snapshot['status'] ?? 'Unknown';
        $created = $snapshot['created_at'] ?? 'Unknown';
        $totalRows = $snapshot['total_rows'] ?? 0;
        $downloadUrl = $snapshot['download_url'] ?? null;

        // Format status with color
        $statusDisplay = match ($status) {
            'ready'   => "✅ {$status}",
            'running' => "🔄 {$status}",
            'failed'  => "❌ {$status}",
            default   => "❓ {$status}"
        };

        $this->info("📋 Snapshot #{$number}:");
        $this->info("   ID: {$id}");
        $this->info("   Status: {$statusDisplay}");
        $this->info("   Created: {$created}");
        $this->info("   Total Rows: {$totalRows}");

        if ($downloadUrl) {
            $this->info('   Download: Available');
        }

        // If running, try to get more details
        if ($status === 'running') {
            $this->checkSnapshotProgress($id);
        }

        $this->line('');
    }

    private function checkSnapshotProgress(string $snapshotId): void
    {
        try {
            $apiKey = env('BRIGHTDATA_SCRAPER_API');
            $client = new Client(['timeout' => 10]);

            $response = $client->get("https://api.brightdata.com/datasets/v3/snapshot/{$snapshotId}", [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            if ($statusCode === 202) {
                $data = json_decode($body, true);
                $message = $data['message'] ?? 'Processing...';
                $this->info("   Progress: {$message}");
            } elseif ($statusCode === 200) {
                $data = json_decode($body, true);
                $status = $data['status'] ?? 'unknown';
                $this->info("   Progress: Status changed to {$status}");
            }
        } catch (\Exception $e) {
            $this->info('   Progress: Unable to check ('.$e->getMessage().')');
        }
    }
}
