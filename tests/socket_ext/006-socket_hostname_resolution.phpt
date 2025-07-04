--TEST--
socket_connect() with hostname resolution async
--SKIPIF--
<?php
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    echo "Client: creating socket\n";
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    
    // Test hostname resolution - connect to localhost (should resolve async)
    echo "Client: resolving hostname\n";
    $result = @socket_connect($socket, "localhost", 65000);
    
    if ($result === false) {
        $error = socket_last_error($socket);
        // Various connection errors are expected when testing hostname resolution
        if ($error === SOCKET_ECONNREFUSED || $error === SOCKET_ENETUNREACH || $error === SOCKET_ETIMEDOUT || $error === SOCKET_ENOENT) {
            echo "Client: hostname resolution worked (connection failed as expected)\n";
        } else {
            echo "Client: unexpected error: " . socket_strerror($error) . "\n";
        }
    } else {
        echo "Client: connection succeeded\n";
        socket_close($socket);
    }
    
    if ($result === false && $error !== SOCKET_ECONNREFUSED && $error !== SOCKET_ENETUNREACH && $error !== SOCKET_ETIMEDOUT && $error !== SOCKET_ENOENT) {
        socket_close($socket);
    }
});

await($coroutine);
echo "End\n";

?>
--EXPECTF--
Start
Client: creating socket
Client: resolving hostname
Client: hostname resolution worked (connection failed as expected)
End