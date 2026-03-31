--TEST--
await_any_or_fail() with already completed coroutine returns immediately
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\await_any_or_fail;
use function Async\suspend;

echo "start\n";

$completed = spawn(function() {
    return "fast";
});

// Let it finish
await($completed);

echo "completed: " . ($completed->isCompleted() ? "true" : "false") . "\n";

// This coroutine will never finish on its own
$slow = spawn(function() {
    suspend();
    suspend();
    suspend();
    return "slow";
});

$result = await_any_or_fail([$completed, $slow]);
echo "result: " . $result . "\n";
echo "end\n";

?>
--EXPECT--
start
completed: true
result: fast
end
