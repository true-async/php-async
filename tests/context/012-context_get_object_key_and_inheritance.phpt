--TEST--
Context: get() reaches parent Scopes, and reports an object key by class
--FILE--
<?php

use function Async\current_context;
use function Async\await;

$context = current_context();
$context->set('inherited', 'V');

// get() searches the parent Scopes, exactly like find() -- only the miss differs.
$scope = Async\Scope::inherit();
echo "get from a child Scope: ", var_export(await($scope->spawn(fn() => current_context()->get('inherited'))), true), "\n";

// getLocal() stops at the local Context, so the inherited key is a miss there.
echo "getLocal from a child Scope: ", await($scope->spawn(function () {
    try {
        current_context()->getLocal('inherited');
        return 'NOT THROWN';
    } catch (Async\ContextException $exception) {
        return $exception->getMessage();
    }
})), "\n";

$key = new stdClass();

try {
    $context->get($key);
    echo "object key: NOT THROWN\n";
} catch (Async\ContextException $exception) {
    echo "object key: ", $exception->getMessage(), "\n";
}

$context->set($key, 'OBJ');
echo "object key, present: ", var_export($context->get($key), true), "\n";
?>
--EXPECT--
get from a child Scope: 'V'
getLocal from a child Scope: Context key "inherited" not found
object key: Context key of type stdClass not found
object key, present: 'OBJ'
