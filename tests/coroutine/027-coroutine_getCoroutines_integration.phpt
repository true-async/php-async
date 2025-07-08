--TEST--
getCoroutines() - integration with coroutine lifecycle management
--FILE--
<?php

use function Async\spawn;
use function Async\getCoroutines;
use function Async\suspend;
use function Async\awaitAll;

echo "start\n";

// Track initial state
$initial_count = count(getCoroutines());
echo "Initial count: {$initial_count}\n";

// Test 1: Create multiple coroutines and track changes
$coroutines = [];
for ($i = 0; $i < 5; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        suspend();
        return "result_{$i}";
    });
}

$after_spawn = count(getCoroutines()) - $initial_count;
echo "After spawning 5: {$after_spawn}\n";

// Verify all coroutines are in the list
$all_coroutines = getCoroutines();
$our_coroutines = array_slice($all_coroutines, $initial_count, 5);
echo "Found our coroutines: " . count($our_coroutines) . "\n";

foreach ($our_coroutines as $index => $coroutine) {
    echo "Coroutine {$index} is suspended: " . ($coroutine->isSuspended() ? "true" : "false") . "\n";
}

// Test 2: Cancel some coroutines
$coroutines[0]->cancel();
$coroutines[2]->cancel();

$after_partial_cancel = count(getCoroutines()) - $initial_count;
echo "After cancelling 2: {$after_partial_cancel}\n";

// Test 3: Complete remaining coroutines
$remaining = [$coroutines[1], $coroutines[3], $coroutines[4]];
$results = awaitAll($remaining);
echo "Completed results: " . count($results) . "\n";

$final_count = count(getCoroutines()) - $initial_count;
echo "Final count: {$final_count}\n";

// Test 4: Verify getCoroutines() consistency during concurrent operations
$concurrent_coroutines = [];
for ($i = 0; $i < 3; $i++) {
    $concurrent_coroutines[] = spawn(function() use ($i) {
        $count_before = count(getCoroutines());
        suspend();
        $count_after = count(getCoroutines());
        return "coroutine_{$i}: before={$count_before}, after={$count_after}";
    });
}

$concurrent_results = awaitAll($concurrent_coroutines);
foreach ($concurrent_results as $result) {
    echo "Concurrent: {$result}\n";
}

echo "end\n";

?>
--EXPECTF--
start
Initial count: 0
After spawning 5: 5
Found our coroutines: 5
Coroutine 0 is suspended: true
Coroutine 1 is suspended: true
Coroutine 2 is suspended: true
Coroutine 3 is suspended: true
Coroutine 4 is suspended: true
After cancelling 2: 3
Completed results: 3
Final count: 0
Concurrent: coroutine_0: before=%d, after=%d
Concurrent: coroutine_1: before=%d, after=%d
Concurrent: coroutine_2: before=%d, after=%d
end