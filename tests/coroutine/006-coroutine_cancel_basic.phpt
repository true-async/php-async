--TEST--
Coroutine: cancel() - basic usage with CancellationError
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\CancellationError;

$coroutine = spawn(function() {
    return "should not complete";
});

$cancellation = new CancellationError("test cancellation");
$coroutine->cancel($cancellation);

var_dump($coroutine->isCancellationRequested());
var_dump($coroutine->isCancelled());

try {
    await($coroutine);
} catch (CancellationError $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
bool(true)
bool(false)
Caught: test cancellation