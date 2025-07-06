--TEST--
awaitAny() - Exception in generator body should stop process immediately
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
use function Async\suspend;

function exceptionGenerator($functions) {
    $count = 0;
    foreach ($functions as $func) {
        // Throw exception on first iteration
        if ($count === 0) {
            throw new RuntimeException("Generator exception during iteration");
        }
        
        yield spawn($func);
        $count++;
    }
}

echo "start\n";

$functions = [
    function() { return "fast"; },
    function() { suspend(); return "slow"; },
];

$generator = exceptionGenerator($functions);

try {
    $result = awaitAny($generator);
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