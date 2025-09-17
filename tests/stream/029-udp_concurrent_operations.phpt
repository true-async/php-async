--TEST--
Concurrent UDP operations with multiple servers and clients in async context
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "Start concurrent UDP operations test\n";

// Create multiple UDP servers
$servers = [];
$server_addresses = [];

for ($i = 0; $i < 3; $i++) {
    $servers[] = spawn(function() use ($i, &$server_addresses) {
        $socket = stream_socket_server("udp://127.0.0.1:0", $errno, $errstr);
        if (!$socket) {
            echo "Server $i: failed to create socket\n";
            return;
        }

        $address = stream_socket_get_name($socket, false);
        $server_addresses[$i] = $address;
        echo "Server $i: listening on $address\n";

        // Handle multiple clients
        for ($j = 0; $j < 2; $j++) {
            $data = stream_socket_recvfrom($socket, 1024, 0, $peer);
            echo "Server $i: received '$data' from client\n";

            $response = "Response from server $i to message $j";
            stream_socket_sendto($socket, $response, 0, $peer);
        }

        fclose($socket);
        return $address;
    });
}

// Create multiple clients for each server
$clients = [];
for ($i = 0; $i < 3; $i++) {
    for ($j = 0; $j < 2; $j++) {
        $clients[] = spawn(function() use ($i, $j, &$server_addresses) {
            // Wait for server address with retry logic
            $address = null;
            for ($attempts = 0; $attempts < 5; $attempts++) {
                delay(10);
                if (isset($server_addresses[$i])) {
                    $address = $server_addresses[$i];
                    break;
                }
            }

            if (!$address) {
                throw new Exception("Client $i-$j: server address not ready after 5 attempts");
            }

            $socket = stream_socket_client($address, $errno, $errstr);
            if (!$socket) {
                echo "Client $i-$j: failed to connect\n";
                return;
            }

            $message = "Message from client $i-$j";
            stream_socket_sendto($socket, $message);

            $response = stream_socket_recvfrom($socket, 1024);
            echo "Client $i-$j: received '$response'\n";

            fclose($socket);
        });
    }
}

// Background worker
$worker = spawn(function() {
    for ($i = 0; $i < 5; $i++) {
        echo "Worker: iteration $i\n";
        delay(10);
    }
});

awaitAll(array_merge($servers, $clients, [$worker]));
echo "End concurrent UDP operations test\n";

?>
--EXPECTF--
Start concurrent UDP operations test
Server 0: listening on udp://127.0.0.1:%d
Server 1: listening on udp://127.0.0.1:%d
Server 2: listening on udp://127.0.0.1:%d
Worker: iteration 0
%a
Server %d: received 'Message from client %d-%d' from client
Client %d-%d: received 'Response from server %d to message %d'
%a
Worker: iteration %d
%a
End concurrent UDP operations test