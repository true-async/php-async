--TEST--
awaitAnyOrFail() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAnyOrFail;
use function Async\await;
use function Async\suspend;

function createCoroutines() {
    yield spawn(function() {
        suspend();
        return "slow";
    });
    
    yield spawn(function() {
        return "fast";
    });
    
    yield spawn(function() {
        return "medium";
    });
}

echo "start\n";

$generator = createCoroutines();
$result = awaitAnyOrFail($generator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end