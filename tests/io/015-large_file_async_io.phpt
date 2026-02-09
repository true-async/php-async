--TEST--
Large file write and read through async IO path
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $size = 256 * 1024;
    $payload = str_repeat('X', $size);

    // Write large data
    $fp = fopen($tmpfile, 'w');
    $written = 0;
    $len = strlen($payload);
    while ($written < $len) {
        $chunk = substr($payload, $written, 8192);
        $result = fwrite($fp, $chunk);
        if ($result === false || $result === 0) {
            echo "Write stalled at $written\n";
            break;
        }
        $written += $result;
    }
    fclose($fp);
    echo "Written: $written\n";

    // Read it back
    $fp = fopen($tmpfile, 'r');
    $received = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $received .= $chunk;
    }
    fclose($fp);

    echo "Read: " . strlen($received) . "\n";
    echo "Match: " . ($received === $payload ? "yes" : "no") . "\n";
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Written: 262144
Read: 262144
Match: yes
Result: done
End
