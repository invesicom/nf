<?php

namespace App\Console\Commands;

use App\Http\Controllers\AnalysisController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class TestAnalysisWorkflow extends Command
{
    protected $signature = 'test:analysis-workflow {url} {--poll-interval=2}';
    protected $description = 'Test the complete analysis workflow including progress polling';

    public function handle(): int
    {
        $url = $this->argument('url');
        $pollInterval = (int) $this->option('poll-interval');

        $this->info("Testing analysis workflow for: {$url}");
        $this->info("Poll interval: {$pollInterval} seconds");
        $this->newLine();

        // Step 1: Start analysis (simulate API call)
        $this->info('🚀 Step 1: Starting analysis...');

        $controller = app(AnalysisController::class);
        $request = Request::create('/api/analysis/start', 'POST', [
            'productUrl' => $url,
        ]);

        // Simulate session
        $request->setLaravelSession(app('session.store'));
        $request->session()->start();

        try {
            $response = $controller->startAnalysis($request);
            $responseData = json_decode($response->getContent(), true);

            if (!$responseData['success']) {
                $this->error('❌ Failed to start analysis: '.$responseData['error']);

                return 1;
            }

            $sessionId = $responseData['session_id'];
            $this->info('✅ Analysis started successfully');
            $this->info("📋 Session ID: {$sessionId}");
            $this->newLine();
        } catch (\Exception $e) {
            $this->error('❌ Exception starting analysis: '.$e->getMessage());

            return 1;
        }

        // Step 2: Poll progress until completion
        $this->info('📊 Step 2: Polling progress...');
        $this->newLine();

        $maxAttempts = 60; // 2 minutes max
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                $progressRequest = Request::create("/api/analysis/progress/{$sessionId}", 'GET');
                $progressRequest->setLaravelSession($request->session());

                $progressResponse = $controller->getProgress($progressRequest, $sessionId);
                $progressData = json_decode($progressResponse->getContent(), true);

                if (!$progressData['success']) {
                    $this->error('❌ Progress check failed: '.$progressData['error']);

                    return 1;
                }

                $status = $progressData['status'];
                $percentage = $progressData['progress_percentage'] ?? 0;
                $message = $progressData['current_message'] ?? 'Processing...';

                $this->line("📈 Progress: {$percentage}% - {$message} (Status: {$status})");

                if ($status === 'completed') {
                    $this->newLine();
                    $this->info('✅ Analysis completed successfully!');

                    if (isset($progressData['redirect_url']) && $progressData['redirect_url']) {
                        $this->info('🔗 Redirect URL: '.$progressData['redirect_url']);
                    } else {
                        $this->warn('⚠️  No redirect URL provided');
                    }

                    // Show final result details
                    $this->showFinalResults($progressData);

                    return 0;
                } elseif ($status === 'failed') {
                    $this->newLine();
                    $this->error('❌ Analysis failed: '.($progressData['error'] ?? 'Unknown error'));

                    return 1;
                }

                sleep($pollInterval);
            } catch (\Exception $e) {
                $this->error('❌ Exception during progress check: '.$e->getMessage());

                return 1;
            }
        }

        $this->error('❌ Analysis timed out after '.($maxAttempts * $pollInterval).' seconds');

        return 1;
    }

    private function showFinalResults(array $progressData): void
    {
        $this->newLine();
        $this->info('📋 Final Results:');

        if (isset($progressData['result'])) {
            $result = $progressData['result'];

            if (isset($result['asin_data'])) {
                $asinData = $result['asin_data'];

                $this->line('   ASIN: '.($asinData['asin'] ?? 'N/A'));
                $this->line('   Status: '.($asinData['status'] ?? 'N/A'));
                $this->line('   Grade: '.($asinData['grade'] ?? 'N/A'));
                $this->line('   Fake Percentage: '.($asinData['fake_percentage'] ?? 'N/A').'%');
                $reviews = $asinData['reviews'] ?? [];
                if (is_string($reviews)) {
                    $reviews = json_decode($reviews, true) ?? [];
                }
                $this->line('   Reviews Count: '.count($reviews));
                $this->line('   Product Title: '.($asinData['product_title'] ?? 'N/A'));
                $this->line('   Have Product Data: '.($asinData['have_product_data'] ? 'Yes' : 'No'));
            }
        }

        $this->newLine();
        $this->info('🔍 Check logs for detailed execution trace');
    }
}
