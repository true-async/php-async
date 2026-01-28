--TEST--
await_any_or_fail() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\await_any_or_fail;
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
$result = await_any_or_fail($generator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end