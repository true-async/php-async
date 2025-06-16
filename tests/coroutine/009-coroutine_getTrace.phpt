--TEST--
Coroutine: getTrace() - returns empty array (TODO implementation)
--FILE--
<?php

use function Async\spawn;

$coroutine = spawn(function() {
    return "test";
});

$trace = $coroutine->getTrace();

var_dump(is_array($trace));
var_dump(count($trace));

?>
--EXPECT--
bool(true)
int(0)