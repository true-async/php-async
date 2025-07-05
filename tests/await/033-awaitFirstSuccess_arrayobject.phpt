--TEST--
awaitFirstSuccess() - with ArrayObject
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;
use function Async\delay;

$arrayObject = new ArrayObject([
    spawn(function() {
        delay(10);
        throw new RuntimeException("error");
    }),
    
    spawn(function() {
        delay(20);
        return "success";
    }),
    
    spawn(function() {
        delay(30);
        return "another success";
    })
]);

echo "start\n";

$result = awaitFirstSuccess($arrayObject);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECT--
start
Result: success
end