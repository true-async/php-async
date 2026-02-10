--TEST--
stream_select detects data already buffered in PHP stream read buffer
--FILE--
<?php
use function Async\spawn;
use function Async\await;

/**
 * Regression test: when fgets() reads from a TCP socket, it may pull more data
 * into PHP's internal stream buffer than the single line it returns. A subsequent
 * stream_select() must detect this buffered data immediately rather than waiting
 * for the OS-level socket to become readable (which it won't — data is already
 * consumed from the kernel buffer).
 */
$coroutine = spawn(function () {
    $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$server) {
        throw new RuntimeException("Cannot create server: $errstr ($errno)");
    }

    $addr = stream_socket_get_name($server, false);
    $client = stream_socket_client("tcp://$addr", $errno, $errstr, 1);
    $accepted = stream_socket_accept($server, 1);

    // Send two lines — TCP will likely deliver them in a single segment
    fwrite($client, "line1\nline2\n");
    fflush($client);

    // Small pause to ensure data arrives at the accepted socket
    usleep(50000);

    // Read only the first line; "line2\n" stays in PHP's stream buffer
    $first = fgets($accepted);
    echo "fgets: " . trim($first) . "\n";

    // stream_select must detect buffered "line2\n" immediately
    $read = [$accepted];
    $write = $except = null;

    $t = microtime(true);
    $ready = stream_select($read, $write, $except, 5);
    $elapsed = microtime(true) - $t;

    echo "ready: $ready\n";
    echo "fast: " . ($elapsed < 1.0 ? "yes" : "no") . "\n";

    // Read the buffered line
    $second = fgets($accepted);
    echo "fgets: " . trim($second) . "\n";

    fclose($client);
    fclose($accepted);
    fclose($server);
});

await($coroutine);
?>
--EXPECT--
fgets: line1
ready: 1
fast: yes
fgets: line2
