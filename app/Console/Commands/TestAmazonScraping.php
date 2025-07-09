<?php

namespace App\Console\Commands;

use App\Services\Amazon\AmazonScrapingService;
use App\Services\ReviewAnalysisService;
use Illuminate\Console\Command;

class TestAmazonScraping extends Command
{
    protected $signature = 'test:amazon-scraping {asin} {--reviews-only} {--full-analysis}';
    
    protected $description = 'Test Amazon scraping service with a specific ASIN';

    public function handle()
    {
        $asin = $this->argument('asin');
        $reviewsOnly = $this->option('reviews-only');
        $fullAnalysis = $this->option('full-analysis');
        
        $this->info("Testing Amazon scraping for ASIN: {$asin}");
        $this->newLine();
        
        try {
            $scrapingService = new AmazonScrapingService();
            
            if ($reviewsOnly) {
                $this->testReviewsOnly($scrapingService, $asin);
            } elseif ($fullAnalysis) {
                $this->testFullAnalysis($asin);
            } else {
                $this->testBasicScraping($scrapingService, $asin);
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
        }
    }
    
    private function testReviewsOnly($scrapingService, $asin)
    {
        $this->info("Fetching reviews only...");
        
        $reviewsData = $scrapingService->fetchReviews($asin);
        $reviews = $reviewsData['reviews'] ?? [];
        
        $this->info("Found " . count($reviews) . " reviews");
        $this->newLine();
        
        // Show first few reviews
        foreach (array_slice($reviews, 0, 3) as $i => $review) {
            $this->info("Review " . ($i + 1) . ":");
            $this->line("  ID: " . ($review['id'] ?? 'N/A'));
            $this->line("  Rating: " . ($review['rating'] ?? 'N/A'));
            $this->line("  Title: " . substr($review['review_title'] ?? 'No title', 0, 50) . "...");
            $this->line("  Text: " . substr($review['review_text'] ?? 'No text', 0, 100) . "...");
            $this->line("  Author: " . ($review['author'] ?? 'Anonymous'));
            $this->newLine();
        }
    }
    
    private function testBasicScraping($scrapingService, $asin)
    {
        $this->info("Testing basic scraping...");
        
        $reviewsData = $scrapingService->fetchReviews($asin);
        
        $this->info("Scraping Results:");
        $this->line("  Description: " . substr($reviewsData['description'] ?? 'N/A', 0, 80) . "...");
        $this->line("  Total Reviews: " . ($reviewsData['total_reviews'] ?? 'N/A'));
        $this->newLine();
        
        $reviews = $reviewsData['reviews'] ?? [];
        $this->info("Found " . count($reviews) . " reviews");
        
        if (count($reviews) > 0) {
            $this->info("Sample review structure:");
            $sample = $reviews[0];
            foreach ($sample as $key => $value) {
                $displayValue = is_string($value) ? substr($value, 0, 50) . "..." : $value;
                $this->line("  {$key}: {$displayValue}");
            }
        }
    }
    
    private function testFullAnalysis($asin)
    {
        $this->info("Running full analysis pipeline...");
        
        $analysisService = app(ReviewAnalysisService::class);
        $result = $analysisService->analyzeProduct($asin);
        
        $this->info("Analysis Results:");
        $this->line("  Fake Percentage: " . ($result['fake_percentage'] ?? 'N/A') . "%");
        $this->line("  Grade: " . ($result['grade'] ?? 'N/A'));
        $this->line("  Total Reviews: " . ($result['total_reviews'] ?? 'N/A'));
        $this->line("  Amazon Rating: " . ($result['amazon_rating'] ?? 'N/A'));
        $this->line("  Adjusted Rating: " . ($result['adjusted_rating'] ?? 'N/A'));
        
        if (isset($result['analysis_details'])) {
            $this->newLine();
            $this->info("Analysis Details:");
            $details = $result['analysis_details'];
            $this->line("  Positive Reviews: " . ($details['positive_reviews'] ?? 'N/A'));
            $this->line("  Negative Reviews: " . ($details['negative_reviews'] ?? 'N/A'));
            $this->line("  Neutral Reviews: " . ($details['neutral_reviews'] ?? 'N/A'));
        }
    }
} 