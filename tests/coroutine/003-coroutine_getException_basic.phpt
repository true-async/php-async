--TEST--
Coroutine: getException() - basic usage
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Test with normal completion
$coroutine1 = spawn(function() {
    return "success";
});

await($coroutine1);
var_dump($coroutine1->getException());

// Test with exception
$coroutine2 = spawn(function() {
    throw new RuntimeException("test error");
});

try {
    await($coroutine2);
} catch (Exception $e) {
    // Ignore
}

$exception = $coroutine2->getException();
var_dump($exception instanceof RuntimeException);
var_dump($exception->getMessage());

?>
--EXPECT--
NULL
bool(true)
string(10) "test error"