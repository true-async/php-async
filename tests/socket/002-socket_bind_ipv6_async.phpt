--TEST--
socket_bind() with IPv6 hostname resolution in async context
--SKIPIF--
<?php
if (getenv("CI_NO_IPV6")) {
    die('skip IPv6 tests disabled in CI environment');
}
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
if (!defined('AF_INET6')) {
    die('skip IPv6 not supported');
}
if (!socket_create(AF_INET6, SOCK_STREAM, SOL_TCP)) {
    die('skip IPv6 sockets not supported');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function () {
    // Test IPv6 hostname resolution in socket_bind
    $socket = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
    
    if (!$socket) {
        echo "Failed to create socket\n";
        return;
    }
    
    // Enable address reuse
    socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
    
    // Test with localhost IPv6 - this should resolve asynchronously
    $result = @socket_bind($socket, "::1", 0); // Port 0 lets system choose available port
    
    if ($result === false) {
        $error = socket_last_error($socket);
        echo "Bind failed: " . socket_strerror($error) . "\n";
    } else {
        echo "IPv6 bind succeeded\n";
        
        // Try to get the bound address to verify it worked
        $addr = '';
        $port = 0;
        if (socket_getsockname($socket, $addr, $port)) {
            echo "Bound to IPv6 address: $addr:$port\n";
        }
    }
    
    socket_close($socket);
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECTF--
IPv6 bind succeeded
Bound to IPv6 address: ::1:%s
Test completed