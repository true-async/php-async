<?php
/**
 * Benchmark: Async Coroutines switching performance
 * Tests the performance of async coroutines context switching
 */

// Increase memory limit for benchmark
ini_set('memory_limit', '512M');

use function Async\spawn;
use function Async\awaitAll;

echo "=== Async Coroutines Benchmark ===\n\n";

// Test configuration
$iterations = 1000;
$switches = 1000;

// Benchmark async coroutines
function benchmarkCoroutines($iterations, $switches) {
    $start = microtime(true);
    $memoryBeforeCreate = getCurrentMemoryUsage();
    
    $coroutines = [];
    for ($i = 0; $i < $iterations; $i++) {
        $coroutines[] = spawn(function() use ($switches) {
            for ($j = 0; $j < $switches; $j++) {
                // Yield control to other coroutines
                Async\suspend();
            }
        });
    }
    
    $memoryAfterCreate = getCurrentMemoryUsage();
    
    awaitAll($coroutines);
    
    $end = microtime(true);
    return [
        'time' => $end - $start,
        'memoryBeforeCreate' => $memoryBeforeCreate,
        'memoryAfterCreate' => $memoryAfterCreate,
        'creationOverhead' => $memoryAfterCreate - $memoryBeforeCreate
    ];
}

// Memory usage tracking
function getCurrentMemoryUsage() {
    return memory_get_usage(true);
}

function getPeakMemoryUsage() {
    return memory_get_peak_usage(true);
}

// Run benchmark
echo "Configuration:\n";
echo "- Iterations: $iterations\n";
echo "- Switches per iteration: $switches\n";
echo "- Total context switches: " . ($iterations * $switches) . "\n\n";

// Memory usage before benchmark
$memoryBefore = getCurrentMemoryUsage();

// Warmup
echo "Warming up...\n";
benchmarkCoroutines(100, 10);

echo "\nRunning coroutines benchmark...\n";

// Benchmark coroutines
$result = benchmarkCoroutines($iterations, $switches);
$coroutineTime = $result['time'];

// Memory usage after benchmark
$memoryAfter = getCurrentMemoryUsage();
$memoryPeak = getPeakMemoryUsage();

// Results
echo "\n=== Results ===\n";
echo "Time: " . number_format($coroutineTime, 4) . " seconds\n";
echo "Switches per second: " . number_format(($iterations * $switches) / $coroutineTime, 0) . "\n";
echo "Overhead per switch: " . number_format(($coroutineTime / ($iterations * $switches)) * 1000000, 2) . " μs\n";

echo "\nMemory Usage:\n";
echo "Before: " . number_format($memoryBefore / 1024 / 1024, 2) . " MB\n";
echo "After creation: " . number_format($result['memoryAfterCreate'] / 1024 / 1024, 2) . " MB\n";
echo "After completion: " . number_format($memoryAfter / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . number_format($memoryPeak / 1024 / 1024, 2) . " MB\n";
echo "Creation overhead: " . number_format($result['creationOverhead'] / 1024 / 1024, 2) . " MB\n";
echo "Used for benchmark: " . number_format(($memoryAfter - $memoryBefore) / 1024 / 1024, 2) . " MB\n";

// Additional metrics
$totalSwitches = $iterations * $switches;
echo "\nPerformance Metrics:\n";
echo "Total coroutines created: $iterations\n";
echo "Total context switches: $totalSwitches\n";
echo "Average time per coroutine: " . number_format($coroutineTime / $iterations * 1000, 2) . " ms\n";
echo "Memory per coroutine (creation): " . number_format($result['creationOverhead'] / $iterations, 0) . " bytes\n";
echo "Memory per coroutine (total): " . number_format(($memoryAfter - $memoryBefore) / $iterations, 0) . " bytes\n";

echo "\nCoroutines benchmark completed.\n";