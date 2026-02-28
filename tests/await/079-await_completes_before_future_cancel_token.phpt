--TEST--
await() completes normally when awaitable resolves before Future cancel token fires
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$state = new FutureState();
$cancel = new Future($state);

$coroutine = spawn(function() use ($cancel) {
    $worker = spawn(function() {
        return "success";
    });

    $result = await($worker, $cancel);
    echo "Result: $result\n";
});

await($coroutine);

echo "Done\n";

?>
--EXPECT--
Result: success
Done
