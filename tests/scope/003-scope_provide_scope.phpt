--TEST--
Scope: provideScope() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();
$providedScope = $scope->provideScope();

var_dump($providedScope === $scope);
var_dump($providedScope instanceof Scope);

?>
--EXPECT--
bool(true)
bool(true)