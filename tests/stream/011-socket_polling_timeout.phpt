--TEST--
Poll2 async: Socket polling with timeout behavior
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Testing socket timeout behavior\n";

$coroutine = spawn(function() {
    echo "Creating socket pair\n";
    
    $sockets = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (!$sockets) {
        echo "Failed to create socket pair\n";
        return;
    }
    
    list($sock1, $sock2) = $sockets;
    
    // Set read timeout
    stream_set_timeout($sock2, 0, 2000); // 2 ms timeout
    
    echo "Attempting to read from empty socket (should timeout)\n";
    $start_time = microtime(true);
    
    // This should trigger the async polling mechanism
    $data = fread($sock2, 1024);
    $meta = stream_get_meta_data($sock2);

    var_dump($meta['timed_out']);
    
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
--EXPECTF--
Testing socket timeout behavior
Creating socket pair
Attempting to read from empty socket (should timeout)
bool(true)
Read completed, data: 'false'
Elapsed time: %s seconds
Writing data and reading it back
Successfully read: 'test data'
Result: timeout test completed