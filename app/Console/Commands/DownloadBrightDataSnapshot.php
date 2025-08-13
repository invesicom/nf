<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;

class DownloadBrightDataSnapshot extends Command
{
    protected $signature = 'brightdata:download {snapshot_id}';
    protected $description = 'Download and inspect a BrightData snapshot';

    public function handle()
    {
        $snapshotId = $this->argument('snapshot_id');
        
        $this->info("📥 Downloading BrightData Snapshot");
        $this->info("Snapshot ID: {$snapshotId}");
        $this->line("=" . str_repeat("=", 50));

        $apiKey = env('BRIGHTDATA_SCRAPER_API');

        if (empty($apiKey)) {
            $this->error("❌ BRIGHTDATA_SCRAPER_API not configured");
            return 1;
        }

        try {
            $client = new Client(['timeout' => 30]);

            $response = $client->get("https://api.brightdata.com/datasets/v3/snapshot/{$snapshotId}", [
                'headers' => [
                    'Authorization' => "Bearer {$apiKey}",
                ],
                'query' => [
                    'format' => 'json'
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            $this->info("📊 Response Status: {$statusCode}");
            $this->info("📊 Response Size: " . strlen($body) . " bytes");

            if ($statusCode !== 200) {
                $this->error("❌ Download failed with status {$statusCode}");
                $this->error("Response: " . substr($body, 0, 500));
                return 1;
            }

            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("❌ Invalid JSON response");
                $this->info("Raw response: " . substr($body, 0, 500));
                return 1;
            }

            if (!is_array($data)) {
                $this->error("❌ Response is not an array");
                $this->info("Response type: " . gettype($data));
                $this->info("Response: " . substr($body, 0, 500));
                return 1;
            }

            $this->info("✅ Successfully downloaded snapshot data");
            $this->info("📊 Records Count: " . count($data));
            $this->line("");

            if (empty($data)) {
                $this->warn("⚠️  No data in snapshot - this explains the 0 rows!");
                $this->info("💡 Possible reasons:");
                $this->info("   1. ASIN doesn't exist or has no reviews");
                $this->info("   2. BrightData couldn't access the product");
                $this->info("   3. Anti-bot protection blocked the scraper");
                $this->info("   4. Dataset configuration issue");
                return 0;
            }

            // Show sample of first few records
            $sampleSize = min(3, count($data));
            $this->info("📋 Sample Records (first {$sampleSize}):");
            $this->line("-" . str_repeat("-", 40));

            for ($i = 0; $i < $sampleSize; $i++) {
                $record = $data[$i];
                $this->info("🔹 Record " . ($i + 1) . ":");
                
                // Show key fields if they exist
                $fieldsToShow = [
                    'url', 'asin', 'product_name', 'review_id', 'review_text', 
                    'rating', 'author_name', 'review_posted_date'
                ];
                
                foreach ($fieldsToShow as $field) {
                    if (isset($record[$field])) {
                        $value = $record[$field];
                        if (is_string($value) && strlen($value) > 100) {
                            $value = substr($value, 0, 100) . '...';
                        }
                        $this->info("   {$field}: {$value}");
                    }
                }
                $this->line("");
            }

            if (count($data) > $sampleSize) {
                $remaining = count($data) - $sampleSize;
                $this->info("... and {$remaining} more records");
            }

            $this->line("");
            $this->info("🏁 Download completed successfully");
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Failed to download snapshot: " . $e->getMessage());
            return 1;
        }
    }
}
