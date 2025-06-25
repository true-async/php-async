--TEST--
Scope: onFinally() - basic execution of finally handler
--FILE--
<?php

use Async\Scope;
use function Async\await;

$called = false;
$scope = new Scope();

$coroutine = $scope->spawn(function() {
    return "result";
});

$scope->onFinally(function() use (&$called) {
    $called = true;
    echo "Finally handler executed\n";
});

await($coroutine);
$scope->dispose();

echo "Finally called: " . ($called ? "yes" : "no") . "\n";

?>
--EXPECT--
Finally handler executed
Finally called: yes