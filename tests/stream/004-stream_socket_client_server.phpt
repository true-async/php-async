--TEST--
stream_socket_client and stream_socket_server with coroutine switching
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "Start\n";

// Server coroutine
$server = spawn(function() {
    echo "Server: creating socket\n";
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed to create socket\n";
        return;
    }
    
    $address = stream_socket_get_name($socket, false);
    echo "Server: listening on $address\n";
    
    // This should allow other coroutines to run
    echo "Server: waiting for connection\n";
    $client = stream_socket_accept($socket);
    echo "Server: client connected\n";
    
    $data = fread($client, 1024);
    echo "Server: received '$data'\n";
    
    fwrite($client, "Hello from server");
    echo "Server: response sent\n";
    
    fclose($client);
    fclose($socket);
});

// Worker coroutine to show parallel execution
$worker = spawn(function() {
    echo "Worker: doing work while server waits\n";
    echo "Worker: more work\n";
    echo "Worker: finished\n";
});

awaitAll([$server, $worker]);
echo "End\n";

?>
--EXPECT--
Start
Server: creating socket
Server: listening on tcp://127.0.0.1:0
Server: waiting for connection
Worker: doing work while server waits
Worker: more work
Worker: finished
End