--TEST--
Coroutine: cancel() - basic usage with CancellationException
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\CancellationException;

$coroutine = spawn(function() {
    return "should not complete";
});

$cancellation = new CancellationException("test cancellation");
$coroutine->cancel($cancellation);

var_dump($coroutine->isCancellationRequested());
var_dump($coroutine->isCancelled());

try {
    await($coroutine);
} catch (CancellationException $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}

?>
--EXPECT--
bool(true)
bool(false)
Caught: test cancellation