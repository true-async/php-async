--TEST--
Async\spawnWith: returns Coroutine instance
--FILE--
<?php

use function Async\spawn_with;
use function Async\await;
use Async\Scope;
use Async\Coroutine;

$scope = new Scope();

$coroutine = spawn_with($scope, function() {
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