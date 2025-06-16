--TEST--
Coroutine: status methods - isStarted, isFinished, isCancelled, isSuspended
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    return "test";
});

echo "After spawn:\n";
var_dump($coroutine->isStarted());
var_dump($coroutine->isFinished());
var_dump($coroutine->isCancelled());

await($coroutine);

echo "After completion:\n";
var_dump($coroutine->isStarted());
var_dump($coroutine->isFinished());
var_dump($coroutine->isCancelled());

?>
--EXPECT--
After spawn:
bool(false)
bool(false)
bool(false)
After completion:
bool(true)
bool(true)
bool(false)