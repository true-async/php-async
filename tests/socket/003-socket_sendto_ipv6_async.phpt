--TEST--
socket_sendto() with IPv6 hostname resolution in async context
--SKIPIF--
<?php
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
if (!defined('AF_INET6')) {
    die('skip IPv6 not supported');
}
if (!socket_create(AF_INET6, SOCK_DGRAM, SOL_UDP)) {
    die('skip IPv6 UDP sockets not supported');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function () {
    // Test IPv6 hostname resolution in socket_sendto
    $socket = socket_create(AF_INET6, SOCK_DGRAM, SOL_UDP);
    
    if (!$socket) {
        echo "Failed to create socket\n";
        return;
    }
    
    $message = "Hello IPv6 world!";
    
    // Test with localhost IPv6 - this should resolve asynchronously
    $result = @socket_sendto($socket, $message, strlen($message), 0, "::1", 12345);
    
    if ($result === false) {
        $error = socket_last_error($socket);
        // For UDP, send usually succeeds even if destination is unreachable
        // But we want to verify hostname resolution worked
        echo "Sendto failed: " . socket_strerror($error) . "\n";
    } else {
        echo "IPv6 sendto succeeded, sent $result bytes\n";
        
        if ($result == strlen($message)) {
            echo "Message sent successfully to IPv6 address\n";
        }
    }
    
    socket_close($socket);
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
IPv6 sendto succeeded, sent 17 bytes
Message sent successfully to IPv6 address
Test completed