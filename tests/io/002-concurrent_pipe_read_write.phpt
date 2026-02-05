--TEST--
Concurrent pipe read/write between coroutines
--FILE--
<?php

require_once __DIR__ . '/../stream/stream_helper.php';

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$sockets = create_socket_pair();
list($sock1, $sock2) = $sockets;

$writer = spawn(function() use ($sock1) {
    fwrite($sock1, "message-one");
    fwrite($sock1, "|message-two");
    fwrite($sock1, "|message-three");
    fclose($sock1);
    return "writer done";
});

$reader = spawn(function() use ($sock2) {
    $all = '';
    while (!feof($sock2)) {
        $chunk = fread($sock2, 1024);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $all .= $chunk;
    }
    fclose($sock2);
    return $all;
});

[$results, $exceptions] = await_all([$writer, $reader]);

echo "Writer: " . $results[0] . "\n";
echo "Reader: " . $results[1] . "\n";
echo "End\n";

?>
--EXPECT--
Start
Writer: writer done
Reader: message-one|message-two|message-three
End
