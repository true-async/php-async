<?php
/**
 * Benchmark: PHP Fibers switching performance
 * Tests the performance of PHP fibers context switching
 */

// Increase memory limit for benchmark
ini_set('memory_limit', '512M');

echo "=== PHP Fibers Benchmark ===\n\n";

// Check if Fibers are available
if (!class_exists('Fiber')) {
    echo "ERROR: PHP Fibers not available (requires PHP 8.1+)\n";
    echo "Current PHP version: " . PHP_VERSION . "\n";
    exit(1);
}

// Test configuration
$iterations = 1000;
$switches = 1000;

// Benchmark PHP fibers
function benchmarkFibers($iterations, $switches) {
    $start = microtime(true);
    $memoryBeforeCreate = getCurrentMemoryUsage();
    
    $fibers = [];
    for ($i = 0; $i < $iterations; $i++) {
        $fibers[] = new Fiber(function() use ($switches) {
            for ($j = 0; $j < $switches; $j++) {
                Fiber::suspend();
            }
        });
    }
    
    $memoryAfterCreate = getCurrentMemoryUsage();
    
    // Start all fibers and switch between them
    foreach ($fibers as $fiber) {
        $fiber->start();
    }
    
    $memoryAfterStart = getCurrentMemoryUsage();
    
    while (true) {
        $alive = false;
        foreach ($fibers as $fiber) {
            if (!$fiber->isTerminated()) {
                $alive = true;
                $fiber->resume();
            }
        }
        if (!$alive) break;
    }
    
    $end = microtime(true);
    return [
        'time' => $end - $start,
        'memoryBeforeCreate' => $memoryBeforeCreate,
        'memoryAfterCreate' => $memoryAfterCreate,
        'memoryAfterStart' => $memoryAfterStart,
        'creationOverhead' => $memoryAfterCreate - $memoryBeforeCreate,
        'startOverhead' => $memoryAfterStart - $memoryAfterCreate
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
echo "- PHP Version: " . PHP_VERSION . "\n";
echo "- Iterations: $iterations\n";
echo "- Switches per iteration: $switches\n";
echo "- Total context switches: " . ($iterations * $switches) . "\n\n";

// Memory usage before benchmark
$memoryBefore = getCurrentMemoryUsage();

// Warmup
echo "Warming up...\n";
benchmarkFibers(100, 10);

echo "\nRunning fibers benchmark...\n";

// Benchmark fibers
$result = benchmarkFibers($iterations, $switches);
$fiberTime = $result['time'];

// Memory usage after benchmark
$memoryAfter = getCurrentMemoryUsage();
$memoryPeak = getPeakMemoryUsage();

// Results
echo "\n=== Results ===\n";
echo "Time: " . number_format($fiberTime, 4) . " seconds\n";
echo "Switches per second: " . number_format(($iterations * $switches) / $fiberTime, 0) . "\n";
echo "Overhead per switch: " . number_format(($fiberTime / ($iterations * $switches)) * 1000000, 2) . " μs\n";

echo "\nMemory Usage:\n";
echo "Before: " . number_format($memoryBefore / 1024 / 1024, 2) . " MB\n";
echo "After creation: " . number_format($result['memoryAfterCreate'] / 1024 / 1024, 2) . " MB\n";
echo "After start: " . number_format($result['memoryAfterStart'] / 1024 / 1024, 2) . " MB\n";
echo "After completion: " . number_format($memoryAfter / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . number_format($memoryPeak / 1024 / 1024, 2) . " MB\n";
echo "Creation overhead: " . number_format($result['creationOverhead'] / 1024 / 1024, 2) . " MB\n";
echo "Start overhead: " . number_format($result['startOverhead'] / 1024 / 1024, 2) . " MB\n";
echo "Used for benchmark: " . number_format(($memoryAfter - $memoryBefore) / 1024 / 1024, 2) . " MB\n";

// Additional metrics
$totalSwitches = $iterations * $switches;
echo "\nPerformance Metrics:\n";
echo "Total fibers created: $iterations\n";
echo "Total context switches: $totalSwitches\n";
echo "Average time per fiber: " . number_format($fiberTime / $iterations * 1000, 2) . " ms\n";
echo "Memory per fiber (creation): " . number_format($result['creationOverhead'] / $iterations, 0) . " bytes\n";
echo "Memory per fiber (start): " . number_format($result['startOverhead'] / $iterations, 0) . " bytes\n";
echo "Memory per fiber (total): " . number_format(($memoryAfter - $memoryBefore) / $iterations, 0) . " bytes\n";

echo "\nFibers benchmark completed.\n";