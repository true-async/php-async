--TEST--
Coroutine: getAwaitingInfo() - detailed testing with different states
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "start\n";

// Test 1: getAwaitingInfo() for running coroutine
$running = spawn(function() {
    return "test";
});

$info = $running->getAwaitingInfo();
echo "Running coroutine info type: " . gettype($info) . "\n";
echo "Running coroutine info is array: " . (is_array($info) ? "true" : "false") . "\n";

// Wait for completion
await($running);

// Test 2: getAwaitingInfo() for finished coroutine
$info2 = $running->getAwaitingInfo();
echo "Finished coroutine info type: " . gettype($info2) . "\n";
echo "Finished coroutine info is array: " . (is_array($info2) ? "true" : "false") . "\n";

// Test 3: getAwaitingInfo() for suspended coroutine
$suspended = spawn(function() {
    suspend();
    return "suspended";
});

$info3 = $suspended->getAwaitingInfo();
echo "Suspended coroutine info type: " . gettype($info3) . "\n";
echo "Suspended coroutine info is array: " . (is_array($info3) ? "true" : "false") . "\n";

// Test 4: getAwaitingInfo() for cancelled coroutine
$suspended->cancel();
$info4 = $suspended->getAwaitingInfo();
echo "Cancelled coroutine info type: " . gettype($info4) . "\n";
echo "Cancelled coroutine info is array: " . (is_array($info4) ? "true" : "false") . "\n";

echo "end\n";

?>
--EXPECT--
start
Running coroutine info type: array
Running coroutine info is array: true
Finished coroutine info type: array
Finished coroutine info is array: true
Suspended coroutine info type: array
Suspended coroutine info is array: true
Cancelled coroutine info type: array
Cancelled coroutine info is array: true
end