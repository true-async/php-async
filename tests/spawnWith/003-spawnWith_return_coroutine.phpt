--TEST--
Async\spawnWith: returns Coroutine instance
--FILE--
<?php

use function Async\spawnWith;
use function Async\await;
use Async\Scope;
use Async\Coroutine;

$scope = new Scope();

$coroutine = spawnWith($scope, function() {
    return "test";
});

var_dump($coroutine instanceof Coroutine);
var_dump(is_int($coroutine->getId()));

$result = await($coroutine);
var_dump($result);

?>
--EXPECT--
bool(true)
bool(true)
string(4) "test"