--TEST--
feof() returns false when unread data is pending
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
    fwrite($client, "data in buffer");
    $s2c->send("sent");
    $c2s->recv();
    fclose($client);
    fclose($socket);
});

$client = spawn(function() use ($s2c, $c2s) {
    $address = $s2c->recv();
    $sock = stream_socket_client("tcp://$address", $errno, $errstr, 5);
    $s2c->recv();

    \Async\delay(1);

    echo "feof with pending data: " . (feof($sock) ? "true (BUG!)" : "false") . "\n";

    $data = fread($sock, 1024);
    echo "read: $data\n";
    echo "feof after reading all: " . (feof($sock) ? "true (BUG!)" : "false") . "\n";

    $c2s->send("done");
    fclose($sock);
});

await_all([$server, $client]);

?>
--EXPECT--
feof with pending data: false
read: data in buffer
feof after reading all: false
