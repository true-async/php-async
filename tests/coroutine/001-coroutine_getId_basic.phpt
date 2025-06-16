--TEST--
Coroutine: getId() - basic usage
--FILE--
<?php

use function Async\spawn;

$coroutine1 = spawn(function() {
    return "test1";
});

$coroutine2 = spawn(function() {
    return "test2";
});

$id1 = $coroutine1->getId();
$id2 = $coroutine2->getId();

var_dump(is_int($id1));
var_dump(is_int($id2));
var_dump($id1 !== $id2);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)