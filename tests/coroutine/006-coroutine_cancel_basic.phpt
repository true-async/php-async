--TEST--
Coroutine: cancel() - basic usage with AsyncCancellation
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\AsyncCancellation;

$coroutine = spawn(function() {
    return "should not complete";
});

$cancellation = new AsyncCancellation("test cancellation");
$coroutine->cancel($cancellation);

var_dump($coroutine->isCancellationRequested());
var_dump($coroutine->isCancelled());

try {
    await($coroutine);
} catch (AsyncCancellation $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
bool(true)
bool(false)
Caught: test cancellation