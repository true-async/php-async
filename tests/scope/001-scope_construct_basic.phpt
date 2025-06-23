--TEST--
Scope: __construct() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();
var_dump($scope instanceof Scope);
var_dump($scope instanceof Async\ScopeProvider);

?>
--EXPECT--
bool(true)
bool(true)