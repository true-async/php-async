--TEST--
Coroutine: getContext() - returns null (TODO Context API implementation)
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

$context = $coroutine->getContext();

var_dump($context);

?>
--EXPECT--
NULL