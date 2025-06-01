--TEST--
Poll2 async: Error handling in async polling context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Testing error handling in async polling\n";

$coroutine = spawn(function() {
    echo "Creating socket pair\n";
    
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return;
    }
    
    list($sock1, $sock2) = $sockets;
    
    // Set non-blocking mode
    stream_set_blocking($sock1, false);
    stream_set_blocking($sock2, false);
    
    echo "Testing operations on closed socket\n";
    
    // Close one socket
    fclose($sock1);
    
    // Try to read from closed socket
    echo "Attempting to read from closed socket\n";
    $data = fread($sock1, 1024);
    echo "Read result: " . var_export($data, true) . "\n";
    
    // Try to write to the remaining socket
    echo "Writing to remaining socket\n";
    $result = fwrite($sock2, "test data");
    echo "Write result: " . var_export($result, true) . "\n";
    
    // Try to read from the remaining socket (should be empty)
    echo "Reading from remaining socket\n";
    $data = fread($sock2, 1024);
    echo "Read result: " . var_export($data, true) . "\n";
    
    fclose($sock2);
    
    return "error handling test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECTF--
Testing error handling in async polling
Creating socket pair
Testing operations on closed socket
Attempting to read from closed socket

Warning: fread(): %s in %s on line %d
Read result: false
Writing to remaining socket
Write result: 9
Reading from remaining socket
Read result: ''
Result: error handling test completed