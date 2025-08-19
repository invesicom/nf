<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== TESTING OLLAMA ISSUE ===\n";

// Get the actual product that was stuck
$product = \App\Models\AsinData::where('asin', 'B005EJH6RW')->first();
if (!$product) {
    echo "Product B005EJH6RW not found\n";
    exit(1);
}

echo "Product: {$product->asin}\n";
$reviews = $product->getReviewsArray();
echo "Total reviews: " . count($reviews) . "\n";

// Test with different batch sizes
$testSizes = [1, 3, 5, 10];

foreach ($testSizes as $size) {
    echo "\n--- Testing with $size reviews ---\n";
    $testReviews = array_slice($reviews, 0, $size);
    
    try {
        $provider = new \App\Services\Providers\OllamaProvider();
        $start = microtime(true);
        
        echo "Calling Ollama...\n";
        $result = $provider->analyzeReviews($testReviews);
        
        $duration = microtime(true) - $start;
        echo "SUCCESS! Duration: " . round($duration, 2) . " seconds\n";
        echo "Scores returned: " . count($result['detailed_scores']) . "\n";
        
        // Show first score as example
        if (!empty($result['detailed_scores'])) {
            $firstScore = reset($result['detailed_scores']);
            echo "Example score: {$firstScore['score']} ({$firstScore['label']})\n";
        }
        
    } catch (Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        echo "Error occurred at $size reviews\n";
        break;
    }
}

echo "\n=== TEST COMPLETE ===\n";
