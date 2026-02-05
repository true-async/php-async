--TEST--
Large data transfer through pipes in coroutines
--FILE--
<?php

require_once __DIR__ . '/../stream/stream_helper.php';

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;

$size = 64 * 1024;
$payload = str_repeat('X', $size);

$writer = spawn(function() use ($sock1, $payload) {
    $written = 0;
    $len = strlen($payload);
    while ($written < $len) {
        $chunk = substr($payload, $written, 8192);
        $result = fwrite($sock1, $chunk);
        if ($result === false || $result === 0) {
            break;
        }
        $written += $result;
    }
    fclose($sock1);
    return $written;
});

$reader = spawn(function() use ($sock2) {
    $received = '';
    while (!feof($sock2)) {
        $chunk = fread($sock2, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $received .= $chunk;
    }
    fclose($sock2);
    return strlen($received);
});

[$results, $exceptions] = await_all([$writer, $reader]);

$written = $results[0];
$read = $results[1];

echo "Written: $written\n";
echo "Read: $read\n";
echo "Match: " . ($written === $read ? "yes" : "no") . "\n";
echo "End\n";

?>
--EXPECT--
Start
Written: 65536
Read: 65536
Match: yes
End
