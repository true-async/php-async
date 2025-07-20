<?php
/**
 * Comparison script for Coroutines vs Fibers benchmarks
 * Run both benchmarks and compare results
 */

// Increase memory limit for benchmark
ini_set('memory_limit', '512M');

echo "=== Coroutines vs Fibers Comparison ===\n\n";

// Configuration
$iterations = 1000;
$switches = 50;

echo "Configuration:\n";
echo "- Iterations: $iterations\n";
echo "- Switches per iteration: $switches\n";
echo "- Total context switches: " . ($iterations * $switches) . "\n\n";

// Function to run external benchmark and capture output
function runBenchmark($script) {
    $output = [];
    $return_var = 0;
    exec("php " . __DIR__ . "/$script 2>&1", $output, $return_var);
    return [
        'output' => implode("\n", $output),
        'success' => $return_var === 0
    ];
}

// Function to parse benchmark results from output
function parseResults($output) {
    $results = [];
    
    if (preg_match('/Time: ([0-9.]+) seconds/', $output, $matches)) {
        $results['time'] = (float)$matches[1];
    }
    
    if (preg_match('/Switches per second: ([0-9,]+)/', $output, $matches)) {
        $results['switches_per_sec'] = (int)str_replace(',', '', $matches[1]);
    }
    
    if (preg_match('/Overhead per switch: ([0-9.]+) μs/', $output, $matches)) {
        $results['overhead_us'] = (float)$matches[1];
    }
    
    if (preg_match('/Used for benchmark: ([0-9.]+) MB/', $output, $matches)) {
        $results['memory_mb'] = (float)$matches[1];
    }
    
    return $results;
}

echo "Running coroutines benchmark...\n";
$coroutineResult = runBenchmark('coroutines_benchmark.php');

echo "Running fibers benchmark...\n";
$fiberResult = runBenchmark('fibers_benchmark.php');

echo "\n" . str_repeat("=", 60) . "\n";
echo "COMPARISON RESULTS\n";
echo str_repeat("=", 60) . "\n\n";

if (!$coroutineResult['success']) {
    echo "❌ Coroutines benchmark failed:\n";
    echo $coroutineResult['output'] . "\n\n";
} else {
    echo "✅ Coroutines benchmark completed successfully\n\n";
}

if (!$fiberResult['success']) {
    echo "❌ Fibers benchmark failed:\n";
    echo $fiberResult['output'] . "\n\n";
} else {
    echo "✅ Fibers benchmark completed successfully\n\n";
}

// Parse and compare results if both succeeded
if ($coroutineResult['success'] && $fiberResult['success']) {
    $coroutineStats = parseResults($coroutineResult['output']);
    $fiberStats = parseResults($fiberResult['output']);
    
    echo "📊 PERFORMANCE COMPARISON:\n\n";
    
    // Time comparison
    if (isset($coroutineStats['time']) && isset($fiberStats['time'])) {
        $timeRatio = $fiberStats['time'] / $coroutineStats['time'];
        echo "⏱️  Execution Time:\n";
        echo "   Coroutines: " . number_format($coroutineStats['time'], 4) . "s\n";
        echo "   Fibers:     " . number_format($fiberStats['time'], 4) . "s\n";
        if ($timeRatio > 1) {
            echo "   🏆 Coroutines are " . number_format($timeRatio, 2) . "x faster\n\n";
        } else {
            echo "   🏆 Fibers are " . number_format(1/$timeRatio, 2) . "x faster\n\n";
        }
    }
    
    // Throughput comparison
    if (isset($coroutineStats['switches_per_sec']) && isset($fiberStats['switches_per_sec'])) {
        echo "🚀 Throughput (switches/sec):\n";
        echo "   Coroutines: " . number_format($coroutineStats['switches_per_sec']) . "\n";
        echo "   Fibers:     " . number_format($fiberStats['switches_per_sec']) . "\n";
        $throughputRatio = $coroutineStats['switches_per_sec'] / $fiberStats['switches_per_sec'];
        if ($throughputRatio > 1) {
            echo "   🏆 Coroutines have " . number_format($throughputRatio, 2) . "x higher throughput\n\n";
        } else {
            echo "   🏆 Fibers have " . number_format(1/$throughputRatio, 2) . "x higher throughput\n\n";
        }
    }
    
    // Overhead comparison
    if (isset($coroutineStats['overhead_us']) && isset($fiberStats['overhead_us'])) {
        echo "⚡ Overhead per switch:\n";
        echo "   Coroutines: " . number_format($coroutineStats['overhead_us'], 2) . " μs\n";
        echo "   Fibers:     " . number_format($fiberStats['overhead_us'], 2) . " μs\n";
        $overheadRatio = $fiberStats['overhead_us'] / $coroutineStats['overhead_us'];
        if ($overheadRatio > 1) {
            echo "   🏆 Coroutines have " . number_format($overheadRatio, 2) . "x lower overhead\n\n";
        } else {
            echo "   🏆 Fibers have " . number_format(1/$overheadRatio, 2) . "x lower overhead\n\n";
        }
    }
    
    // Memory comparison
    if (isset($coroutineStats['memory_mb']) && isset($fiberStats['memory_mb'])) {
        echo "💾 Memory Usage:\n";
        echo "   Coroutines: " . number_format($coroutineStats['memory_mb'], 2) . " MB\n";
        echo "   Fibers:     " . number_format($fiberStats['memory_mb'], 2) . " MB\n";
        $memoryRatio = $fiberStats['memory_mb'] / $coroutineStats['memory_mb'];
        if ($memoryRatio > 1) {
            echo "   🏆 Coroutines use " . number_format($memoryRatio, 2) . "x less memory\n\n";
        } else {
            echo "   🏆 Fibers use " . number_format(1/$memoryRatio, 2) . "x less memory\n\n";
        }
    }
} else {
    echo "⚠️  Cannot compare results - one or both benchmarks failed\n";
}

echo "💡 Note: Results may vary based on system load and configuration\n";
echo "💡 Run multiple times and average results for production comparisons\n";

echo "\nComparison completed.\n";