--TEST--
awaitFirstSuccess() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\awaitFirstSuccess;
use function Async\await;

function createCoroutines() {
    yield spawn(function() {
        throw new RuntimeException("error");
    });
    
    yield spawn(function() {
        return "success";
    });
    
    yield spawn(function() {
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