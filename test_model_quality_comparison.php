<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Providers\OllamaProvider;
use Illuminate\Support\Facades\Http;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test reviews with known characteristics for quality assessment
$testReviews = [
    // Obviously fake reviews
    [
        'id' => 'fake_1',
        'rating' => 5,
        'review_text' => 'Amazing product! Best ever! Perfect! Highly recommend! Five stars! Excellent quality! Fast shipping! Great seller! Buy now!',
        'meta_data' => ['verified_purchase' => false],
        'helpful_votes' => 0
    ],
    [
        'id' => 'fake_2', 
        'rating' => 5,
        'review_text' => 'This product is very good very good very good. I like it very much very much. Quality is good good good. Recommend recommend recommend.',
        'meta_data' => ['verified_purchase' => false],
        'helpful_votes' => 1
    ],
    
    // Obviously genuine reviews
    [
        'id' => 'genuine_1',
        'rating' => 4,
        'review_text' => 'Works well for my home office setup. The light is bright enough but not harsh. Installation was straightforward - just clips onto my monitor. Only minor complaint is the touch controls are a bit sensitive, but overall happy with the purchase.',
        'meta_data' => ['verified_purchase' => true],
        'helpful_votes' => 8
    ],
    [
        'id' => 'genuine_2',
        'rating' => 3,
        'review_text' => 'Decent monitor light but has some issues. The auto-dimming feature works inconsistently - sometimes it gets too bright in the evening. Build quality feels solid though. For the price, it does the job but there might be better options.',
        'meta_data' => ['verified_purchase' => true],
        'helpful_votes' => 12
    ],
    
    // Borderline/uncertain cases
    [
        'id' => 'uncertain_1',
        'rating' => 5,
        'review_text' => 'Great monitor light! Really helps reduce eye strain during long work sessions. Easy to install and the touch controls work well. Good value for money.',
        'meta_data' => ['verified_purchase' => true],
        'helpful_votes' => 5
    ],
    [
        'id' => 'uncertain_2',
        'rating' => 1,
        'review_text' => 'Stopped working after 2 weeks. Very disappointed. Would not recommend.',
        'meta_data' => ['verified_purchase' => false],
        'helpful_votes' => 3
    ]
];

function testModelQuality($model, $reviews) {
    echo "\n=== Testing Model: {$model} ===\n";
    
    // Temporarily override the model configuration
    config(['services.ollama.model' => $model]);
    
    try {
        $provider = new OllamaProvider();
        
        if (!$provider->isAvailable()) {
            echo "ERROR: Ollama service not available\n";
            return null;
        }
        
        $startTime = microtime(true);
        $result = $provider->analyzeReviews($reviews);
        $endTime = microtime(true);
        
        $duration = round($endTime - $startTime, 2);
        echo "Analysis completed in {$duration} seconds\n";
        
        if (!isset($result['results']) || !is_array($result['results'])) {
            echo "ERROR: Invalid result format\n";
            return null;
        }
        
        echo "Results:\n";
        foreach ($result['results'] as $analysis) {
            $reviewId = $analysis['review_id'] ?? 'unknown';
            $score = $analysis['fake_score'] ?? 'N/A';
            $label = $analysis['label'] ?? 'unknown';
            
            echo "  {$reviewId}: Score {$score}, Label: {$label}\n";
        }
        
        return [
            'duration' => $duration,
            'results' => $result['results']
        ];
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        return null;
    }
}

function analyzeQuality($results7b, $results3b, $expectedLabels) {
    echo "\n=== Quality Analysis ===\n";
    
    if (!$results7b || !$results3b) {
        echo "Cannot compare - one or both models failed\n";
        return;
    }
    
    echo "Performance Comparison:\n";
    echo "  7B model: {$results7b['duration']}s\n";
    echo "  3B model: {$results3b['duration']}s\n";
    echo "  Speed improvement: " . round($results7b['duration'] / $results3b['duration'], 2) . "x faster\n\n";
    
    echo "Quality Comparison:\n";
    echo sprintf("%-15s %-10s %-10s %-15s %-15s %s\n", 
        "Review ID", "Expected", "7B Result", "3B Result", "7B Score", "3B Score");
    echo str_repeat("-", 80) . "\n";
    
    $agreements = 0;
    $total = 0;
    
    foreach ($expectedLabels as $reviewId => $expected) {
        $result7b = collect($results7b['results'])->firstWhere('review_id', $reviewId);
        $result3b = collect($results3b['results'])->firstWhere('review_id', $reviewId);
        
        if ($result7b && $result3b) {
            $label7b = $result7b['label'] ?? 'unknown';
            $label3b = $result3b['label'] ?? 'unknown';
            $score7b = $result7b['fake_score'] ?? 'N/A';
            $score3b = $result3b['fake_score'] ?? 'N/A';
            
            echo sprintf("%-15s %-10s %-10s %-15s %-15s %s\n", 
                $reviewId, $expected, $label7b, $label3b, $score7b, $score3b);
            
            if ($label7b === $label3b) {
                $agreements++;
            }
            $total++;
        }
    }
    
    if ($total > 0) {
        $agreementRate = round(($agreements / $total) * 100, 1);
        echo "\nAgreement Rate: {$agreements}/{$total} ({$agreementRate}%)\n";
        
        if ($agreementRate >= 80) {
            echo "✓ HIGH AGREEMENT - 3B model maintains quality\n";
        } elseif ($agreementRate >= 60) {
            echo "⚠ MODERATE AGREEMENT - Some quality loss with 3B model\n";
        } else {
            echo "✗ LOW AGREEMENT - Significant quality loss with 3B model\n";
        }
    }
}

echo "Model Quality Comparison Test\n";
echo "============================\n";
echo "Testing qwen2.5:7b vs qwen2.5:3b\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";

// Expected results for quality assessment
$expectedLabels = [
    'fake_1' => 'fake',        // Obviously fake
    'fake_2' => 'fake',        // Obviously fake  
    'genuine_1' => 'genuine',  // Obviously genuine
    'genuine_2' => 'genuine',  // Obviously genuine
    'uncertain_1' => 'uncertain', // Could go either way
    'uncertain_2' => 'uncertain'  // Could go either way
];

// Test both models
$results7b = testModelQuality('qwen2.5:7b', $testReviews);
$results3b = testModelQuality('qwen2.5:3b', $testReviews);

// Analyze quality differences
analyzeQuality($results7b, $results3b, $expectedLabels);

echo "\nTest completed.\n";
