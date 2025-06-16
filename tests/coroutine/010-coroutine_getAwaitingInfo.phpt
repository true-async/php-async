--TEST--
Coroutine: getAwaitingInfo() - basic usage
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

$info = $coroutine->getAwaitingInfo();

var_dump(is_array($info));

?>
--EXPECT--
bool(true)