--TEST--
socket_sendto() and socket_recvfrom() async operations with UDP
--SKIPIF--
<?php
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "Start\n";

$port = null;

// Server coroutine (UDP)
$server = spawn(function() use (&$port) {
    echo "Server: creating UDP socket\n";
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_bind($socket, '127.0.0.1', 0);
    
    $addr = '';
    socket_getsockname($socket, $addr, $port);
    echo "Server: listening on UDP port $port\n";
    
    $buffer = '';
    $from = '';
    $from_port = 0;
    $bytes = socket_recvfrom($socket, $buffer, 1024, 0, $from, $from_port);
    echo "Server: received $bytes bytes from $from:$from_port: '$buffer'\n";
    
    $sent = socket_sendto($socket, "UDP response", 12, 0, $from, $from_port);
    echo "Server: sent $sent bytes back to client\n";
    
    socket_close($socket);
});

// Client coroutine (UDP)
$client = spawn(function() use (&$port) {
    while ($port === null) {
        delay(1);
    }
    
    echo "Client: creating UDP socket\n";
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    
    $sent = socket_sendto($socket, "UDP request", 11, 0, '127.0.0.1', $port);
    echo "Client: sent $sent bytes\n";
    
    $buffer = '';
    $from = '';
    $from_port = 0;
    $bytes = socket_recvfrom($socket, $buffer, 1024, 0, $from, $from_port);
    echo "Client: received $bytes bytes from $from:$from_port: '$buffer'\n";
    
    socket_close($socket);
});

awaitAll([$server, $client]);
echo "End\n";

?>
--EXPECTF--
Start
Server: creating UDP socket
Server: listening on UDP port %d
Client: creating UDP socket
Client: sent 11 bytes
Server: received 11 bytes from 127.0.0.1:%d: 'UDP request'
Server: sent 12 bytes back to client
Client: received 12 bytes from 127.0.0.1:%d: 'UDP response'
End