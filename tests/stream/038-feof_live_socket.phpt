--TEST--
feof() returns false on a live connected socket
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$s2c = new Channel(1); // server -> client
$c2s = new Channel(1); // client -> server

$server = spawn(function() use ($s2c, $c2s) {
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    if (!$socket) {
        echo "Server: failed - $errstr\n";
        return;
    }

    // Signal the address to the client
    $s2c->send(stream_socket_get_name($socket, false));

    $client = stream_socket_accept($socket, 5);
    echo "Server: accepted\n";

    // Signal that connection is established
    $s2c->send("connected");

    // Wait for client to finish feof check
    $c2s->recv();

    // Close client connection
    fclose($client);
    echo "Server: closed client\n";

    // Signal that server closed the connection
    $s2c->send("closed");

    fclose($socket);
});

$client = spawn(function() use ($s2c, $c2s) {
    $address = $s2c->recv();

    $sock = stream_socket_client("tcp://$address", $errno, $errstr, 5);
    if (!$sock) {
        echo "Client: failed - $errstr\n";
        return;
    }
    echo "Client: connected\n";

    // Wait for server to confirm accept
    $s2c->recv();

    // Socket is alive — feof() MUST return false
    $eof = feof($sock);
    echo "feof on live socket: " . ($eof ? "true (BUG!)" : "false") . "\n";

    // Tell server it can close now
    $c2s->send("done");

    // Wait for server to confirm close
    $s2c->recv();

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
--EXPECTF--
Start
%AServer: accepted
%Afeof on live socket: false
Server: closed client
feof after remote close: true
End
