<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== SIMPLE OLLAMA PERFORMANCE TEST ===\n";

function getOllamaCpuUsage() {
    $output = shell_exec("ps aux | grep '[o]llama' | awk '{print \$3}'");
    return $output ? (float)trim($output) : 0;
}

// Get baseline
$baselineCpu = getOllamaCpuUsage();
echo "Baseline Ollama CPU: {$baselineCpu}%\n";

// Get test product
$product = \App\Models\AsinData::where('asin', 'B005EJH6RW')->first();
if (!$product) {
    echo "Product not found\n";
    exit(1);
}

$reviews = $product->getReviewsArray();
echo "Testing with " . count($reviews) . " total reviews\n\n";

// Test with just 1 review first
echo "--- Testing 1 review ---\n";
$testReviews = array_slice($reviews, 0, 1);

$preCpu = getOllamaCpuUsage();
echo "Pre-test CPU: {$preCpu}%\n";

$start = microtime(true);

try {
    $provider = new \App\Services\Providers\OllamaProvider();
    $result = $provider->analyzeReviews($testReviews);
    
    $duration = microtime(true) - $start;
    $postCpu = getOllamaCpuUsage();
    
    echo "SUCCESS: " . round($duration, 2) . " seconds\n";
    echo "Post-test CPU: {$postCpu}%\n";
    echo "CPU increase: " . round($postCpu - $preCpu, 1) . "%\n";
    
    if (isset($result['detailed_scores'])) {
        echo "Scores returned: " . count($result['detailed_scores']) . "\n";
    }
    
} catch (Exception $e) {
    $postCpu = getOllamaCpuUsage();
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "Post-failure CPU: {$postCpu}%\n";
}

echo "\n=== TEST COMPLETE ===\n";
