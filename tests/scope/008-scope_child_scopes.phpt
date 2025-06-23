--TEST--
Scope: getChildScopes() - basic usage
--FILE--
<?php

use Async\Scope;

$parentScope = new Scope();
$childScope1 = Scope::inherit($parentScope);
$childScope2 = Scope::inherit($parentScope);

$children = $parentScope->getChildScopes();

var_dump(is_array($children));
var_dump(count($children));

foreach ($children as $child) {
    var_dump($child instanceof Scope);
}

?>
--EXPECT--
bool(true)
int(2)
bool(true)
bool(true)