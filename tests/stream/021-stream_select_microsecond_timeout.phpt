--TEST--
stream_select with microsecond timeout precision
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select with microsecond timeout\n";

$coroutine = spawn(function() {
    $sockets = create_socket_pair();
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return "microsecond timeout test failed";
    }
    
    list($sock1, $sock2) = $sockets;
    $read = [$sock1];
    $write = $except = null;
    
    $start_time = microtime(true);
    $result = stream_select($read, $write, $except, 0, 5000); // 5ms
    $end_time = microtime(true);
    
    $elapsed = round(($end_time - $start_time) * 1000, 2);
    
    echo "Result: $result\n";
    echo "Elapsed time: {$elapsed}ms\n";
    
    fclose($sock1);
    fclose($sock2);
    
    return "microsecond timeout test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing stream_select with microsecond timeout
Result: 0
Elapsed time: %sms
Result: microsecond timeout test completed