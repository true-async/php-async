--TEST--
awaitFirstSuccess() - when all coroutines throw errors
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;

$coroutines = [
    spawn(function() {
        throw new RuntimeException("first error");
    }),
    
    spawn(function() {
        throw new InvalidArgumentException("second error");
    }),
    
    spawn(function() {
        throw new LogicException("third error");
    })
];

echo "start\n";
$result = awaitFirstSuccess($coroutines);
$error = $result[1] ?? null;
$errorsCount = count($result[1] ?? []);
echo "Result: " . ($result[0] === null ? "NULL" : "FAILED") . "\n";
echo "Errors count: $errorsCount\n";
echo "end\n";

?>
--EXPECT--
start
Result: NULL
Errors count: 3
end