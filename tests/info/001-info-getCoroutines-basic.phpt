--TEST--
getCoroutines() - basic functionality and lifecycle tracking
--FILE--
<?php

use function Async\spawn;
use function Async\getCoroutines;
use function Async\suspend;

echo "start\n";

// Test 1: getCoroutines() without active coroutines
$coroutines = getCoroutines();
echo "Initial coroutines count: " . count($coroutines) . "\n";
echo "Initial coroutines type: " . gettype($coroutines) . "\n";

// Test 2: getCoroutines() with active coroutines
$c1 = spawn(function() {
    suspend();
    return "coroutine1";
});

$c2 = spawn(function() {
    suspend(); 
    return "coroutine2";
});

$coroutines = getCoroutines();
echo "Active coroutines count: " . count($coroutines) . "\n";
echo "First coroutine is Coroutine: " . ($coroutines[0] instanceof \Async\Coroutine ? "true" : "false") . "\n";
echo "Second coroutine is Coroutine: " . ($coroutines[1] instanceof \Async\Coroutine ? "true" : "false") . "\n";

// Test 3: getCoroutines() after cancellation
$c1->cancel();
suspend(); // Allow cancellation to propagate
$coroutines = getCoroutines();
echo "After first cancel count: " . count($coroutines) . "\n";

$c2->cancel();
suspend(); // Allow cancellation to propagate
$coroutines = getCoroutines();
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