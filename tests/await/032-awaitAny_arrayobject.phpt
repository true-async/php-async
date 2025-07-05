--TEST--
awaitAny() - with ArrayObject
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAny;
use function Async\await;
use function Async\delay;

$arrayObject = new ArrayObject([
    spawn(function() {
        delay(50);
        return "slow";
    }),
    
    spawn(function() {
        delay(10);
        return "fast";
    }),
    
    spawn(function() {
        delay(30);
        return "medium";
    })
]);

echo "start\n";

$result = awaitAny($arrayObject);

echo "Result: $result\n";
echo "end\n";

?>
--EXPECT--
start
Result: fast
end