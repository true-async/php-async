--TEST--
get_coroutines() - integration with coroutine lifecycle management
--FILE--
<?php

use function Async\spawn;
use function Async\get_coroutines;
use function Async\current_coroutine;
use function Async\suspend;
use function Async\await_all;

echo "start\n";

// Track initial state
$initial_count = count(get_coroutines());
echo "Initial count: {$initial_count}\n";

// Test 1: Create multiple coroutines and track changes
$coroutines = [];
for ($i = 0; $i < 5; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        suspend();
        return "result_{$i}";
    });
}

$after_spawn = count(get_coroutines()) - $initial_count;
echo "After spawning 5: {$after_spawn}\n";

// Verify all coroutines are in the list
$all_coroutines = get_coroutines();
$currentCoroutine = current_coroutine();

// It’s necessary to check that all coroutines are in the list, regardless of their index.
foreach ($coroutines as $index => $coroutine) {
    if (!in_array($coroutine, $all_coroutines, true)) {
        echo "ERROR: Coroutine $index not found in get_coroutines()\n";
    }
}

if (!in_array($currentCoroutine, $all_coroutines, true)) {
    echo "ERROR: Current coroutine not found in get_coroutines()\n";
}

foreach ($coroutines as $index => $coroutine) {
    echo "Coroutine {$index} is suspended: " . ($coroutine->isSuspended() ? "true" : "false") . "\n";
}

// Test 2: Cancel some coroutines
$coroutines[0]->cancel();
$coroutines[2]->cancel();

// Check if status is updated
foreach ($coroutines as $index => $coroutine) {
    echo "Coroutine {$index} is isCancellationRequested: " . ($coroutine->isCancellationRequested() ? "true" : "false") . "\n";
}

[$results, $exceptions] = await_all($coroutines); // Ensure we yield to allow cancellation to take effect

$after_partial_cancel = count(get_coroutines()) - $initial_count;
echo "After cancelling 2: {$after_partial_cancel}\n";

echo "Completed results: " . count($results) . "\n";

$final_count = count(get_coroutines()) - $initial_count;
echo "Final count: {$final_count}\n";

echo "end\n";

?>
--EXPECTF--
start
Initial count: 0
After spawning 5: 6
Coroutine 0 is suspended: true
Coroutine 1 is suspended: true
Coroutine 2 is suspended: true
Coroutine 3 is suspended: true
Coroutine 4 is suspended: true
Coroutine 0 is isCancellationRequested: true
Coroutine 1 is isCancellationRequested: false
Coroutine 2 is isCancellationRequested: true
Coroutine 3 is isCancellationRequested: false
Coroutine 4 is isCancellationRequested: false
After cancelling 2: 1
Completed results: 3
Final count: 1
end