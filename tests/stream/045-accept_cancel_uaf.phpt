--TEST--
Stream: stream_socket_accept() cancellation does not cause use-after-free
--DESCRIPTION--
When a coroutine blocked in stream_socket_accept() is cancelled during graceful
shutdown, the error_string from network_async_accept_incoming must properly
addref the exception message string. Otherwise, the caller releases it
(zend_string_release_ex), freeing the string while the exception object still
references it — causing heap-use-after-free on exception destruction.
--FILE--
<?php

use Async\Scope;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$server) {
    die("Failed to create server: $errstr ($errno)\n");
}

$address = stream_socket_get_name($server, false);
echo "Server listening on $address\n";

stream_set_blocking($server, false);

$scope = Scope::inherit()->asNotSafely();

// Coroutine that blocks on stream_socket_accept — will be cancelled during
// graceful shutdown when the sibling coroutine throws.
$scope->spawn(function () use ($server) {
    echo "Coroutine 1: waiting for connection\n";
    $client = @stream_socket_accept($server, 30);
    echo "Coroutine 1: done\n";
});

// Coroutine that throws to trigger graceful shutdown of the scope
$scope->spawn(function () {
    echo "Coroutine 2: throwing\n";
    throw new \RuntimeException("Trigger graceful shutdown");
});

try {
    $scope->awaitCompletion(\Async\timeout(5000));
} catch (\Throwable $e) {
    echo "Caught: " . $e::class . ": " . $e->getMessage() . "\n";
}

echo "Cleaning up\n";
fclose($server);
echo "Done\n";

?>
--EXPECTF--
Server listening on %s
Coroutine 1: waiting for connection
Coroutine 2: throwing
Caught: RuntimeException: Trigger graceful shutdown
Cleaning up
Done
