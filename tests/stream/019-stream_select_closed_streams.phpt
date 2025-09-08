--TEST--
stream_select with closed streams
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select with closed streams\n";

$coroutine = spawn(function() {
    $sockets = create_socket_pair();
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return "closed streams test failed";
    }
    
    list($sock1, $sock2) = $sockets;
    
    // Close one socket
    fclose($sock1);
    
    $read = [$sock2];
    $write = $except = null;
    
    try {
        $result = stream_select($read, $write, $except, 0);
        echo "Result: $result\n";
        echo "Read array count: " . count($read) . "\n";
    } catch (Exception $e) {
        echo "Exception: " . get_class($e) . "\n";
    }
    
    fclose($sock2);
    
    return "closed streams test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing stream_select with closed streams
Result: %d
Read array count: %d
Result: closed streams test completed