--TEST--
Scope: disposeAfterTimeout() - basic usage
--FILE--
<?php

use Async\Scope;

$scope = new Scope();

$scope->disposeAfterTimeout(1000);

echo "Dispose after timeout executed successfully\n";

?>
--EXPECT--
Dispose after timeout executed successfully