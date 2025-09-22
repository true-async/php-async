--TEST--
stream_select with empty arrays
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select with empty arrays\n";

$coroutine = spawn(function() {
    $read = $write = $except = [];
    $result = stream_select($read, $write, $except, 1);
    echo "Result: $result\n";
    
    return "empty arrays test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECT--
Testing stream_select with empty arrays
Result: 0
Result: empty arrays test completed