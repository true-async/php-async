--TEST--
awaitFirstSuccess() - with ArrayObject
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;

// We need to create functions instead of coroutines directly
// to ensure proper capturing by awaitFirstSuccess when using ArrayObject
$functions = [
    function() { throw new RuntimeException("error"); },
    function() { return "success"; },
    function() { return "another success"; },
];

// Create coroutines from functions and wrap in ArrayObject
$coroutines = array_map(fn($func) => spawn($func), $functions);
$arrayObject = new ArrayObject($coroutines);

echo "start\n";

$result = awaitFirstSuccess($arrayObject);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECT--
start
Result: success
end