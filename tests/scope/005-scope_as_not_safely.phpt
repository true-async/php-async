--TEST--
Scope: asNotSafely() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();
$notSafeScope = $scope->asNotSafely();

var_dump($notSafeScope === $scope);
var_dump($notSafeScope instanceof Scope);

?>
--EXPECT--
bool(true)
bool(true)