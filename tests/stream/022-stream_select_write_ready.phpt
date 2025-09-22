--TEST--
stream_select with write-ready streams
--FILE--
<?php

require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select write-ready streams\n";

$coroutine = spawn(function() {
    $sockets = create_socket_pair();
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return "write ready test failed";
    }
    
    list($sock1, $sock2) = $sockets;
    
    $read = null;
    $write = [$sock1, $sock2];
    $except = null;
    
    $result = stream_select($read, $write, $except, 1);
    echo "Write-ready streams: $result\n";
    echo "Write array count: " . count($write) . "\n";
    
    foreach ($write as $i => $stream) {
        echo "Stream $i is write-ready\n";
    }
    
    fclose($sock1);
    fclose($sock2);
    
    return "write ready test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing stream_select write-ready streams
Write-ready streams: %d
Write array count: %d
%a
Result: write ready test completed