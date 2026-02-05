--TEST--
Pipe close during IO and error handling
--FILE--
<?php

require_once __DIR__ . '/../stream/stream_helper.php';

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $sockets = create_socket_pair();
    list($writer, $reader) = $sockets;

    fwrite($writer, "hello");

    $data = fread($reader, 1024);
    echo "Read before close: '$data'\n";

    fclose($writer);

    $data = fread($reader, 1024);
    echo "Read after writer closed: '" . $data . "'\n";
    echo "EOF: " . (feof($reader) ? "yes" : "no") . "\n";

    fclose($reader);
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Read before close: 'hello'
Read after writer closed: ''
EOF: yes
Result: done
End
