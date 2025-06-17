--TEST--
Coroutine: asHiPriority() - returns same coroutine (TODO implementation)
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

$hiPriorityCoroutine = $coroutine->asHiPriority();

var_dump($coroutine === $hiPriorityCoroutine);

?>
--EXPECT--
bool(true)