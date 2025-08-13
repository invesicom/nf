<?php

namespace App\Console\Commands;

use App\Models\AnalysisSession;
use App\Models\AsinData;
use App\Services\Amazon\BrightDataScraperService;
use App\Services\ReviewAnalysisService;
use App\Services\LoggingService;
use Illuminate\Console\Command;

class CompleteBrightDataAnalysis extends Command
{
    protected $signature = 'brightdata:complete-analysis {session_id} {job_id}';

    protected $description = 'Manually complete a stuck BrightData analysis session';

    public function handle()
    {
        $sessionId = $this->argument('session_id');
        $jobId = $this->argument('job_id');

        $session = AnalysisSession::find($sessionId);
        if (!$session) {
            $this->error("Analysis session {$sessionId} not found");
            return 1;
        }

        $this->info("Manually completing analysis for session: {$sessionId}");
        $this->info("BrightData job ID: {$jobId}");
        $this->info("ASIN: {$session->asin}");

        try {
            // Create BrightData service and fetch completed data
            $brightDataService = app(BrightDataScraperService::class);
            
            // Use reflection to call private method
            $reflection = new \ReflectionClass($brightDataService);
            $fetchJobDataMethod = $reflection->getMethod('fetchJobData');
            $fetchJobDataMethod->setAccessible(true);
            
            $this->info("Fetching BrightData results...");
            $results = $fetchJobDataMethod->invoke($brightDataService, $jobId);
            
            if (empty($results)) {
                $this->error("No data found in BrightData job {$jobId}");
                return 1;
            }

            $this->info("Found " . count($results) . " reviews from BrightData");

            // Use reflection to call private method for transformation
            $transformMethod = $reflection->getMethod('transformBrightDataResults');
            $transformMethod->setAccessible(true);
            $transformedData = $transformMethod->invoke($brightDataService, $results, $session->asin);

            $this->info("Saving review data to database...");
            
            // Create or update AsinData
            $asinData = AsinData::updateOrCreate(
                ['asin' => $session->asin, 'country' => 'us'],
                [
                    'reviews' => json_encode($transformedData['reviews']),
                    'product_title' => $transformedData['product_name'] ?? '',
                    'product_description' => $transformedData['description'] ?? '',
                    'product_image_url' => $transformedData['product_image_url'] ?? '',
                    'total_reviews_on_amazon' => $transformedData['total_reviews'] ?? count($transformedData['reviews']),
                    'status' => 'pending_analysis',
                    'have_product_data' => true
                ]
            );

            $this->info("Processing with AI analysis...");
            $analysisService = app(ReviewAnalysisService::class);
            
            // Update session progress
            $session->updateProgress(4, 70, 'Analyzing reviews with AI...');
            
            // Analyze with AI
            $asinData = $analysisService->analyzeWithOpenAI($asinData);
            
            // Update session progress
            $session->updateProgress(5, 85, 'Computing authenticity metrics...');
            
            // Calculate final metrics
            $analysisResult = $analysisService->calculateFinalMetrics($asinData);
            
            $session->updateProgress(7, 98, 'Generating final report...');
            
            // Determine redirect URL
            $redirectUrl = null;
            if ($asinData->slug) {
                $redirectUrl = route('amazon.product.show.slug', [
                    'asin' => $asinData->asin,
                    'slug' => $asinData->slug
                ]);
            } else {
                $redirectUrl = route('amazon.product.show', ['asin' => $asinData->asin]);
            }
            
            $finalResult = [
                'success' => true,
                'asin_data' => $asinData->fresh(),
                'analysis_result' => $analysisResult,
                'redirect_url' => $redirectUrl,
            ];

            // Mark session as completed
            $session->markAsCompleted($finalResult);
            
            $this->info("✅ Analysis completed successfully!");
            $this->info("📊 Reviews processed: " . count($transformedData['reviews']));
            $this->info("🎯 Fake percentage: " . ($asinData->fake_percentage ?? 'Not calculated'));
            $this->info("📝 Grade: " . ($asinData->grade ?? 'Not calculated'));
            $this->info("🔗 Redirect URL: " . $redirectUrl);
            
            LoggingService::log('Manual BrightData analysis completion successful', [
                'session_id' => $sessionId,
                'asin' => $session->asin,
                'job_id' => $jobId,
                'reviews_count' => count($transformedData['reviews']),
                'redirect_url' => $redirectUrl
            ]);

            return 0;

        } catch (\Exception $e) {
            $this->error("Failed to complete analysis: " . $e->getMessage());
            
            $session->markAsFailed("Manual completion failed: " . $e->getMessage());
            
            LoggingService::log('Manual BrightData analysis completion failed', [
                'session_id' => $sessionId,
                'asin' => $session->asin,
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);

            return 1;
        }
    }
}
