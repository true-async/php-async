--TEST--
Scope: spawn() - with arguments
--FILE--
<?php

use Async\Scope;

$scope = new Scope();

$coroutine = $scope->spawn(function($a, $b) {
    return $a + $b;
}, 10, 20);

var_dump($coroutine instanceof Async\Coroutine);

?>
--EXPECT--
bool(true)