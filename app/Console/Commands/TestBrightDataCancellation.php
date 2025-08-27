<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;

class TestBrightDataCancellation extends Command
{
    protected $signature = 'brightdata:test-cancel {job_id}';
    protected $description = 'Test BrightData job cancellation API to verify it works';

    public function handle()
    {
        $jobId = $this->argument('job_id');
        $apiKey = env('BRIGHTDATA_SCRAPER_API');

        if (empty($apiKey)) {
            $this->error('BRIGHTDATA_SCRAPER_API not configured');
            return 1;
        }

        $this->info("Testing BrightData cancellation API");
        $this->info("Job ID: {$jobId}");
        $this->info("API Key: " . substr($apiKey, 0, 8) . "...");
        $this->line('');

        // First, check if the job exists and its current status
        $this->info("Step 1: Checking job status before cancellation...");
        $this->checkJobStatus($jobId, $apiKey);

        $this->line('');
        if (!$this->confirm("Proceed with cancellation test? This will actually cancel the job.")) {
            $this->info('Test canceled by user');
            return 0;
        }

        // Test the cancellation endpoint
        $this->info("Step 2: Testing cancellation endpoint...");
        $this->testCancellation($jobId, $apiKey);

        // Check status after cancellation
        $this->info("Step 3: Checking job status after cancellation...");
        sleep(2); // Brief delay
        $this->checkJobStatus($jobId, $apiKey);

        return 0;
    }

    private function checkJobStatus(string $jobId, string $apiKey): void
    {
        try {
            $client = new Client(['timeout' => 30]);
            
            $response = $client->get("https://api.brightdata.com/datasets/v3/progress/{$jobId}", [
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            $this->info("  Status check response:");
            $this->info("    HTTP Status: {$statusCode}");
            
            if ($statusCode === 200) {
                $data = json_decode($body, true);
                if ($data) {
                    $this->info("    Job Status: " . ($data['status'] ?? 'unknown'));
                    $this->info("    Records: " . ($data['records'] ?? 0));
                    $this->info("    Created: " . ($data['created_at'] ?? 'unknown'));
                } else {
                    $this->info("    Response: {$body}");
                }
            } else {
                $this->info("    Response: " . substr($body, 0, 200));
            }
        } catch (\Exception $e) {
            $this->error("  Error checking status: " . $e->getMessage());
        }
    }

    private function testCancellation(string $jobId, string $apiKey): void
    {
        try {
            $client = new Client(['timeout' => 30]);
            
            $endpoint = "https://api.brightdata.com/datasets/v3/snapshot/{$jobId}/cancel";
            $this->info("  Endpoint: {$endpoint}");
            $this->info("  Method: POST");
            
            $response = $client->post($endpoint, [
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
            ]);

            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();

            $this->info("  Cancellation response:");
            $this->info("    HTTP Status: {$statusCode}");
            $this->info("    Response Body: '{$body}'");
            
            if ($statusCode === 200 && trim($body) === 'OK') {
                $this->info("  ✅ Cancellation appears successful!");
            } else {
                $this->warn("  ⚠️  Unexpected response - check BrightData documentation");
            }
        } catch (\Exception $e) {
            $this->error("  ❌ Cancellation failed: " . $e->getMessage());
            
            // Try to get more details from the exception
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $response = $e->getResponse();
                $this->error("    HTTP Status: " . $response->getStatusCode());
                $this->error("    Response: " . $response->getBody()->getContents());
            }
        }
    }
}
