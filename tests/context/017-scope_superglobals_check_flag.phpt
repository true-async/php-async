--TEST--
Scope SuperGlobals - isInheritSuperglobals() method
--FILE--
<?php

$scope_no_inherit = new \Async\Scope(inheritSuperglobals: false);
var_dump($scope_no_inherit->isInheritSuperglobals());

$scope_with_inherit = new \Async\Scope(inheritSuperglobals: true);
var_dump($scope_with_inherit->isInheritSuperglobals());

$scope_default = new \Async\Scope();
var_dump($scope_default->isInheritSuperglobals());

?>
--EXPECT--
bool(false)
bool(true)
bool(true)
