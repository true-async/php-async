--TEST--
stream_select with zero timeout (immediate return)
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select with zero timeout\n";

$coroutine = spawn(function() {
    $sockets = create_socket_pair();
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return "zero timeout test failed";
    }
    
    list($sock1, $sock2) = $sockets;
    $read = [$sock1];
    $write = $except = null;
    
    $start_time = microtime(true);
    $result = stream_select($read, $write, $except, 0);
    $end_time = microtime(true);
    
    $elapsed = round(($end_time - $start_time) * 1000, 2);
    
    echo "Result: $result\n";
    echo "Elapsed time: {$elapsed}ms\n";
    echo "Read array count: " . count($read) . "\n";
    
    fclose($sock1);
    fclose($sock2);
    
    return "zero timeout test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing stream_select with zero timeout
Result: 0
Elapsed time: %sms
Read array count: 0
Result: zero timeout test completed