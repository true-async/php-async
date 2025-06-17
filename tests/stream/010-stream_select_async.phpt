--TEST--
Poll2 async: stream_select behavior in async context
--FILE--
<?php


require_once __DIR__ . '/stream_helper.php';
use function Async\spawn;
use function Async\await;

echo "Testing stream_select in async context\n";

$coroutine = spawn(function() {
    echo "Creating socket pairs\n";
    
    $sockets1 = create_socket_pair();
    $sockets2 = create_socket_pair();
    
    if (!$sockets1 || !$sockets2) {
        echo "Failed to create socket pairs\n";
        return;
    }
    
    list($sock1a, $sock1b) = $sockets1;
    list($sock2a, $sock2b) = $sockets2;
    
    // Write to one socket
    echo "Writing to first socket\n";
    fwrite($sock1a, "data for socket 1");
    
    // Use stream_select to check which sockets are ready
    $read = [$sock1b, $sock2b];
    $write = [];
    $except = [];
    $timeout = 1;
    
    echo "Calling stream_select\n";
    $ready = stream_select($read, $write, $except, $timeout);
    
    echo "stream_select returned: $ready\n";
    echo "Ready sockets count: " . count($read) . "\n";
    
    if ($ready > 0) {
        foreach ($read as $i => $socket) {
            $data = fread($socket, 1024);
            echo "Socket $i has data: '$data'\n";
        }
    }
    
    // Cleanup
    fclose($sock1a);
    fclose($sock1b);
    fclose($sock2a);
    fclose($sock2b);
    
    return "stream_select test completed";
});

$result = await($coroutine);
echo "Result: $result\n";

?>
--EXPECT--
Testing stream_select in async context
Creating socket pairs
Writing to first socket
Calling stream_select
stream_select returned: 1
Ready sockets count: 1
Socket 0 has data: 'data for socket 1'
Result: stream_select test completed