--TEST--
get_coroutines() - basic functionality and lifecycle tracking
--FILE--
<?php

use function Async\spawn;
use function Async\get_coroutines;
use function Async\suspend;

echo "start\n";

// Test 1: get_coroutines() without active coroutines
$coroutines = get_coroutines();
echo "Initial coroutines count: " . count($coroutines) . "\n";
echo "Initial coroutines type: " . gettype($coroutines) . "\n";

// Test 2: get_coroutines() with active coroutines
$c1 = spawn(function() {
    suspend();
    return "coroutine1";
});

$c2 = spawn(function() {
    suspend(); 
    return "coroutine2";
});

$coroutines = get_coroutines();
echo "Active coroutines count: " . count($coroutines) . "\n";
echo "First coroutine is Coroutine: " . ($coroutines[0] instanceof \Async\Coroutine ? "true" : "false") . "\n";
echo "Second coroutine is Coroutine: " . ($coroutines[1] instanceof \Async\Coroutine ? "true" : "false") . "\n";

// Test 3: get_coroutines() after cancellation
$c1->cancel();
suspend(); // Allow cancellation to propagate
$coroutines = get_coroutines();
echo "After first cancel count: " . count($coroutines) . "\n";

$c2->cancel();
suspend(); // Allow cancellation to propagate
$coroutines = get_coroutines();
echo "After second cancel count: " . count($coroutines) . "\n";

echo "end\n";

?>
--EXPECT--
start
Initial coroutines count: 0
Initial coroutines type: array
Active coroutines count: 3
First coroutine is Coroutine: true
Second coroutine is Coroutine: true
After first cancel count: 2
After second cancel count: 1
end