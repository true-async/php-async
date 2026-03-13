--TEST--
Socket stream created in global scope without coroutine — clean shutdown
--FILE--
<?php
require_once __DIR__ . '/../stream/stream_helper.php';

$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;

stream_set_blocking($sock1, false);
stream_set_blocking($sock2, false);

fwrite($sock1, "test data");
$data = fread($sock2, 1024);
echo "Read: $data\n";

// Do NOT close sockets — let shutdown handle cleanup
echo "Done\n";
?>
--EXPECT--
Read: test data
Done
