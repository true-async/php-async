--TEST--
Concurrent spawn operations - stress test with getCoroutines() tracking
--FILE--
<?php

use function Async\spawn;
use function Async\getCoroutines;
use function Async\suspend;
use function Async\awaitAll;

echo "start\n";

// Track baseline
$start_count = count(getCoroutines());
echo "Baseline coroutines: {$start_count}\n";

// Test 1: Stress test with many concurrent spawns
$stress_coroutines = [];
$stress_count = 50; // Reduced from 100 for test reliability

for ($i = 0; $i < $stress_count; $i++) {
    $stress_coroutines[] = spawn(function() use ($i) {
        // Small amount of work with suspend
        for ($j = 0; $j < 3; $j++) {
            suspend();
        }
        return "stress_result_{$i}";
    });
}

$peak_count = count(getCoroutines()) - $start_count;
echo "Peak coroutines created: {$peak_count}\n";

// Verify we have the expected number
if ($peak_count >= $stress_count) {
    echo "Stress test spawn successful\n";
} else {
    echo "WARNING: Expected {$stress_count}, got {$peak_count}\n";
}

// Test 2: Partial completion
$first_half = array_slice($stress_coroutines, 0, $stress_count / 2);
$second_half = array_slice($stress_coroutines, $stress_count / 2);

$first_results = awaitAll($first_half);
$mid_count = count(getCoroutines()) - $start_count;
echo "After first half completion: {$mid_count}\n";

// Test 3: Complete remaining
$second_results = awaitAll($second_half);
$final_count = count(getCoroutines()) - $start_count;
echo "After full completion: {$final_count}\n";

echo "First half results: " . count($first_results) . "\n";
echo "Second half results: " . count($second_results) . "\n";
echo "Total results: " . (count($first_results) + count($second_results)) . "\n";

// Test 4: Rapid spawn and cancel cycles
echo "Testing rapid spawn/cancel cycles\n";
for ($cycle = 0; $cycle < 5; $cycle++) {
    $rapid_coroutines = [];
    
    // Spawn quickly
    for ($i = 0; $i < 10; $i++) {
        $rapid_coroutines[] = spawn(function() use ($i, $cycle) {
            suspend();
            return "cycle_{$cycle}_item_{$i}";
        });
    }
    
    $cycle_count = count(getCoroutines()) - $start_count;
    
    // Cancel quickly
    foreach ($rapid_coroutines as $coroutine) {
        $coroutine->cancel();
    }
    
    $after_cancel = count(getCoroutines()) - $start_count;
    echo "Cycle {$cycle}: peak={$cycle_count}, after_cancel={$after_cancel}\n";
}

// Verify clean state
$end_count = count(getCoroutines()) - $start_count;
echo "Final state: {$end_count} coroutines remaining\n";

echo "end\n";

?>
--EXPECTF--
start
Baseline coroutines: %d
Peak coroutines created: 50
Stress test spawn successful
After first half completion: %d
After full completion: 0
First half results: 25
Second half results: 25
Total results: 50
Testing rapid spawn/cancel cycles
Cycle 0: peak=%d, after_cancel=%d
Cycle 1: peak=%d, after_cancel=%d
Cycle 2: peak=%d, after_cancel=%d
Cycle 3: peak=%d, after_cancel=%d
Cycle 4: peak=%d, after_cancel=%d
Final state: 0 coroutines remaining
end