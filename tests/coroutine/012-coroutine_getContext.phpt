--TEST--
Coroutine: getContext() - returns the context of the coroutine
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

$context = $coroutine->getContext();

var_dump($context);

?>
--EXPECTF--
object(Async\Context)%a