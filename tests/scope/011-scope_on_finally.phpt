--TEST--
Scope: onFinally() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();

$scope->onFinally(function() {
    echo "Finally callback executed\n";
});

echo "Finally callback set successfully\n";

?>
--EXPECT--
Finally callback set successfully