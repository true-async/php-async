--TEST--
Scope: setExceptionHandler() and setChildScopeExceptionHandler() replace existing handler
--FILE--
<?php

use Async\Scope;

// Covers scope.c: L486-495 (free old exception handler when replacing),
//                L515-525 (free old child exception handler when replacing).

$scope = new Scope();

$scope->setExceptionHandler(function () { echo "first\n"; });
$scope->setExceptionHandler(function () { echo "second\n"; });
$scope->setExceptionHandler(function () { echo "third\n"; });

$scope->setChildScopeExceptionHandler(function () { echo "child first\n"; });
$scope->setChildScopeExceptionHandler(function () { echo "child second\n"; });

echo "ok\n";

?>
--EXPECT--
ok
