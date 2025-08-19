<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== OLLAMA PERFORMANCE MONITORING ===\n";

function getOllamaCpuUsage() {
    // Get Ollama process CPU usage
    $output = shell_exec("ps aux | grep '[o]llama' | awk '{print $3}'");
    return $output ? (float)trim($output) : 0;
}

function getSystemLoad() {
    $load = sys_getloadavg();
    return $load[0]; // 1-minute load average
}

function monitorOllamaTest($testName, $callable) {
    echo "\n--- Testing: $testName ---\n";
    
    $initialCpu = getOllamaCpuUsage();
    $initialLoad = getSystemLoad();
    
    echo "Initial Ollama CPU: {$initialCpu}%\n";
    echo "Initial System Load: " . round($initialLoad, 2) . "\n";
    
    $start = microtime(true);
    
    // Start monitoring in background
    $monitorPid = pcntl_fork();
    if ($monitorPid == 0) {
        // Child process - monitor CPU
        while (true) {
            $cpu = getOllamaCpuUsage();
            $load = getSystemLoad();
            
            if ($cpu > 800) { // Kill if CPU > 800%
                echo "EMERGENCY STOP: Ollama CPU at {$cpu}% - killing test\n";
                posix_kill(getppid(), SIGTERM);
                exit(1);
            }
            
            echo "Monitoring: CPU {$cpu}%, Load " . round($load, 2) . "\n";
            sleep(2);
        }
    } else {
        // Parent process - run test
        try {
            $result = $callable();
            $duration = microtime(true) - $start;
            
            // Stop monitoring
            posix_kill($monitorPid, SIGTERM);
            pcntl_wait($status);
            
            $finalCpu = getOllamaCpuUsage();
            $finalLoad = getSystemLoad();
            
            echo "SUCCESS: Completed in " . round($duration, 2) . " seconds\n";
            echo "Final Ollama CPU: {$finalCpu}%\n";
            echo "Final System Load: " . round($finalLoad, 2) . "\n";
            
            return $result;
            
        } catch (Exception $e) {
            posix_kill($monitorPid, SIGTERM);
            pcntl_wait($status);
            
            echo "FAILED: " . $e->getMessage() . "\n";
            echo "Final Ollama CPU: " . getOllamaCpuUsage() . "%\n";
            return false;
        }
    }
}

// Get test product
$product = \App\Models\AsinData::where('asin', 'B005EJH6RW')->first();
if (!$product) {
    echo "Product not found\n";
    exit(1);
}

$reviews = $product->getReviewsArray();
echo "Product: {$product->asin} ({" . count($reviews) . "} reviews)\n";

// Test different approaches with CPU monitoring
$testSizes = [1, 3, 5];

foreach ($testSizes as $size) {
    $result = monitorOllamaTest("$size reviews", function() use ($reviews, $size) {
        $testReviews = array_slice($reviews, 0, $size);
        $provider = new \App\Services\Providers\OllamaProvider();
        return $provider->analyzeReviews($testReviews);
    });
    
    if (!$result) {
        echo "Stopping tests - performance too poor\n";
        break;
    }
    
    sleep(3); // Cool down between tests
}

echo "\n=== MONITORING COMPLETE ===\n";
