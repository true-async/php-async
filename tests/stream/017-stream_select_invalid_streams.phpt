--TEST--
stream_select with invalid stream types
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select with invalid streams\n";

$coroutine = spawn(function() {
    $invalid = ["not a stream", 123, null];
    $write = $except = null;
    
    try {
        $result = stream_select($invalid, $write, $except, 1);
        echo "Result: $result\n";
    } catch (TypeError $e) {
        echo "Exception: " . get_class($e) . "\n";
    }
    
    return "invalid streams test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing stream_select with invalid streams
%a
Result: invalid streams test completed