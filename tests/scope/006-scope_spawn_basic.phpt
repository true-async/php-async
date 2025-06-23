--TEST--
Scope: spawn() - basic usage
--FILE--
<?php

use Async\Scope;
use Async\Coroutine;

$scope = new Scope();

$coroutine = $scope->spawn(function() {
    return "test result";
});

var_dump($coroutine instanceof Coroutine);
var_dump(is_int($coroutine->getId()));

?>
--EXPECT--
bool(true)
bool(true)