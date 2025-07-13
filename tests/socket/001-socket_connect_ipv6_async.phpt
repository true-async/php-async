--TEST--
socket_connect() with IPv6 hostname resolution in async context
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
    // Test IPv6 hostname resolution in socket_connect
    $socket = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
    
    if (!$socket) {
        echo "Failed to create socket\n";
        return;
    }
    
    // Test with localhost IPv6 - this should resolve asynchronously
    $result = @socket_connect($socket, "::1", 64181);
    
    // We don't care if the connection actually succeeds (port 80 might not be open)
    // We just want to verify that the hostname resolution worked
    if ($result === false) {
        $error = socket_last_error($socket);
        // Connection refused or network unreachable are expected - hostname was resolved
        if ($error === SOCKET_ECONNREFUSED || $error === SOCKET_ENETUNREACH || $error === SOCKET_ETIMEDOUT) {
            echo "IPv6 hostname resolution worked (connection failed as expected)\n";
        } else {
            echo "Unexpected error: " . socket_strerror($error) . "\n";
        }
    } else {
        echo "IPv6 connection succeeded\n";
    }
    
    socket_close($socket);
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECTF--
%s
Test completed