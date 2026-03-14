--TEST--
feof() returns false on a live connected socket
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$ch = new Channel(1);

$server = spawn(function() use ($ch) {
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed - $errstr\n";
        return;
    }

    // Signal the address to the client
    $ch->send(stream_socket_get_name($socket, false));

    $client = stream_socket_accept($socket, 5);
    echo "Server: accepted\n";

    // Signal that connection is established
    $ch->send("connected");

    // Wait for client to finish feof check
    $ch->recv();

    // Close client connection
    fclose($client);
    echo "Server: closed client\n";

    // Signal that server closed the connection
    $ch->send("closed");

    fclose($socket);
});

$client = spawn(function() use ($ch) {
    $address = $ch->recv();

    $sock = stream_socket_client("tcp://$address", $errno, $errstr, 5);
    if (!$sock) {
        echo "Client: failed - $errstr\n";
        return;
    }
    echo "Client: connected\n";

    // Wait for server to confirm accept
    $ch->recv();

    // Socket is alive — feof() MUST return false
    $eof = feof($sock);
    echo "feof on live socket: " . ($eof ? "true (BUG!)" : "false") . "\n";

    // Tell server it can close now
    $ch->send("done");

    // Wait for server to confirm close
    $ch->recv();

    // Give TCP stack time to deliver FIN
    \Async\delay(1);

    // Server closed — feof() should return true
    $eof = feof($sock);
    echo "feof after remote close: " . ($eof ? "true" : "false (BUG!)") . "\n";

    fclose($sock);
});

await_all([$server, $client]);

echo "End\n";

?>
--EXPECT--
Start
Server: accepted
Client: connected
Server: closed client
feof on live socket: false
feof after remote close: true
End
