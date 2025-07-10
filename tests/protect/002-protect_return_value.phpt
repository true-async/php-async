--TEST--
Async\protect: should return a value
--FILE--
<?php

use function Async\protect;

$result = protect(function() {
    return "test value";
});

var_dump($result);

?>
--EXPECT--
string(10) "test value"