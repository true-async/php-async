<?php
/**
 * Test TCP client that connects, sends data, pauses and disconnects
 * Usage: php tcp_client_disconnect.php <port>
 */

if ($argc < 2) {
    echo "Usage: php tcp_client_disconnect.php <port>\n";
    exit(1);
}

$port = (int)$argv[1];

echo "Client process: connecting to port $port\n";

// Set appropriate timeout for different platforms
$timeout = (PHP_OS_FAMILY === 'Windows') ? 5 : 2;

$client = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, $timeout);
if (!$client) {
    echo "Client process: failed to connect: $errstr ($errno)\n";
    exit(1);
}

echo "Client process: connected, sending data\n";
fwrite($client, "Hello from external process\n");
fflush($client);

// Pause to simulate processing - longer on Windows for stability
$pause_time = (PHP_OS_FAMILY === 'Windows') ? 150000 : 100000;
usleep($pause_time);

echo "Client process: closing connection abruptly\n";
fclose($client);

// On Windows, give extra time for cleanup
if (PHP_OS_FAMILY === 'Windows') {
    usleep(50000); // 50ms additional cleanup time
}

echo "Client process: exited\n";
exit(0);