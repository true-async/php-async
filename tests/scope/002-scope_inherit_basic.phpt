--TEST--
Scope: inherit() - basic usage
--FILE--
<?php

use Async\Scope;

$parentScope = new Scope();
$childScope = Scope::inherit($parentScope);

var_dump($childScope instanceof Scope);
var_dump($childScope !== $parentScope);

$autoInheritScope = Scope::inherit();
var_dump($autoInheritScope instanceof Scope);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)