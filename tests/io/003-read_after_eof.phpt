--TEST--
Read after EOF returns zero immediately (sync completion)
--FILE--
<?php

require_once __DIR__ . '/../stream/stream_helper.php';

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $sockets = create_socket_pair();
    list($writer, $reader) = $sockets;

    fwrite($writer, "data");
    fclose($writer);

    $first = fread($reader, 1024);
    echo "First read: '$first'\n";

    $eof_check = feof($reader);
    echo "EOF after first read: " . ($eof_check ? "yes" : "no") . "\n";

    // Read again - should get EOF
    $second = fread($reader, 1024);
    echo "Second read length: " . strlen($second) . "\n";
    echo "EOF after second read: " . (feof($reader) ? "yes" : "no") . "\n";

    // Third read after EOF - should be instant (sync completion, no suspend)
    $third = fread($reader, 1024);
    echo "Third read length: " . strlen($third) . "\n";

    fclose($reader);
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
First read: 'data'
EOF after first read: no
Second read length: 0
EOF after second read: yes
Third read length: 0
Result: done
End
