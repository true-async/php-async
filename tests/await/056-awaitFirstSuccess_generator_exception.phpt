--TEST--
awaitFirstSuccess() - Exception in generator body should stop process immediately
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\suspend;

function exceptionGenerator($functions) {
    $count = 0;
    foreach ($functions as $func) {
        // Throw exception on second iteration (after first coroutine fails)
        if ($count === 1) {
            throw new RuntimeException("Generator exception during iteration");
        }
        
        yield spawn($func);
        $count++;
    }
}

echo "start\n";

$functions = [
    function() { throw new RuntimeException("coroutine error"); },
    function() { return "success"; },
    function() { return "another success"; },
];

$generator = exceptionGenerator($functions);

try {
    $result = awaitFirstSuccess($generator);
    echo "This should not be reached\n";
} catch (RuntimeException $e) {
    echo "Caught exception: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECT--
start
Caught exception: Generator exception during iteration
end