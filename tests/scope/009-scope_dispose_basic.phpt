--TEST--
Scope: dispose() and disposeSafely() - basic usage
--FILE--
<?php

use Async\Scope;

$scope1 = new Scope();
$scope1->dispose();

$scope2 = new Scope();
$scope2->disposeSafely();

echo "Dispose methods executed without errors\n";

?>
--EXPECT--
Dispose methods executed without errors