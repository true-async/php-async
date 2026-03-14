--TEST--
feof() returns false after sending data
--FILE--
<?php

use Async\Channel;
use function Async\spawn;
use function Async\await_all;

$s2c = new Channel(1);
$c2s = new Channel(1);

$server = spawn(function() use ($s2c, $c2s) {
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    $s2c->send(stream_socket_get_name($socket, false));
    $client = stream_socket_accept($socket, 5);
    $s2c->send("accepted");
    $c2s->recv();
    fclose($client);
    fclose($socket);
});

$client = spawn(function() use ($s2c, $c2s) {
    $address = $s2c->recv();
    $sock = stream_socket_client("tcp://$address", $errno, $errstr, 5);
    $s2c->recv();

    fwrite($sock, "hello");

    echo "feof after send: " . (feof($sock) ? "true (BUG!)" : "false") . "\n";

    $c2s->send("done");
    fclose($sock);
});

await_all([$server, $client]);

?>
--EXPECT--
feof after send: false
