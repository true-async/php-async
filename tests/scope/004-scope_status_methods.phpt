--TEST--
Scope: isFinished() and isClosed() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();

var_dump($scope->isFinished());
var_dump($scope->isClosed());

$scope->cancel();

var_dump($scope->isClosed());

?>
--EXPECT--
bool(true)
bool(false)
bool(true)