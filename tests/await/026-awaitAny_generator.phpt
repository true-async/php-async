--TEST--
awaitAny() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
use function Async\await;
use function Async\delay;

function createCoroutines() {
    yield spawn(function() {
        delay(50);
        return "slow";
    });
    
    yield spawn(function() {
        delay(10);
        return "fast";
    });
    
    yield spawn(function() {
        delay(30);
        return "medium";
    });
}

echo "start\n";

$generator = createCoroutines();
$result = awaitAny($generator);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end