--TEST--
awaitFirstSuccess() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;
use function Async\delay;

function createCoroutines() {
    yield spawn(function() {
        delay(10);
        throw new RuntimeException("error");
    });
    
    yield spawn(function() {
        delay(20);
        return "success";
    });
    
    yield spawn(function() {
        delay(30);
        return "another success";
    });
}

echo "start\n";

$generator = createCoroutines();
$result = awaitFirstSuccess($generator);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECT--
start
Result: success
end