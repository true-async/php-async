--TEST--
awaitAllOrFail() - Exception in generator body should stop process immediately
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAllOrFail;
use function Async\suspend;

function exceptionGenerator($values) {
    $count = 0;
    foreach ($values as $value) {
        // Throw exception on second iteration
        if ($count === 1) {
            throw new RuntimeException("Generator exception during iteration");
        }
        
        yield spawn(fn() => $value);
        $count++;
    }
}

echo "start\n";

$values = ["first", "second", "third"];
$generator = exceptionGenerator($values);

try {
    $results = awaitAllOrFail($generator);
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