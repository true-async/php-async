--TEST--
Poll2 async: Socket polling with timeout behavior
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Testing socket timeout behavior\n";

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
    
    // Set read timeout
    stream_set_timeout($sock2, 1, 0); // 1 second timeout
    
    echo "Attempting to read from empty socket (should timeout)\n";
    $start_time = microtime(true);
    
    // This should trigger the async polling mechanism
    $data = fread($sock2, 1024);
    
    $end_time = microtime(true);
    $elapsed = $end_time - $start_time;
    
    echo "Read completed, data: '" . var_export($data, true) . "'\n";
    echo "Elapsed time: " . round($elapsed, 2) . " seconds\n";
    
    // Now write something and read it
    echo "Writing data and reading it back\n";
    fwrite($sock1, "test data");
    $data = fread($sock2, 1024);
    echo "Successfully read: '$data'\n";
    
    fclose($sock1);
    fclose($sock2);
    
    return "timeout test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECT--
Testing socket timeout behavior
Creating socket pair
Attempting to read from empty socket (should timeout)
Read completed, data: ''
Elapsed time: 0 seconds
Writing data and reading it back
Successfully read: 'test data'
Result: timeout test completed