--TEST--
await_first_success() - with Generator
--FILE--
<?php

use function Async\spawn;
use function Async\await_first_success;
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
$result = await_first_success($generator);

echo "Result: {$result[0]}\n";
echo "end\n";

?>
--EXPECT--
start
Result: success
end