--TEST--
Scope: finally() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();

$scope->finally(function() {
    echo "Finally callback executed\n";
});

echo "Finally callback set successfully\n";

?>
--EXPECT--
Finally callback set successfully
Finally callback executed