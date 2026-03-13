--TEST--
w+ mode: comprehensive position tracking across writes, seeks, and reads
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');

    // File is empty; position must be 0
    echo "ftell on open: " . ftell($fp) . "\n";

    // Write 10 bytes
    fwrite($fp, 'ABCDEFGHIJ');
    echo "ftell after write(10): " . ftell($fp) . "\n";

    // Seek back to middle
    fseek($fp, 5, SEEK_SET);
    echo "ftell after fseek(5): " . ftell($fp) . "\n";

    // Read 3 bytes
    $d = fread($fp, 3);
    echo "read(3): '$d'\n";
    echo "ftell after read(3): " . ftell($fp) . "\n";

    // Overwrite 2 bytes at current position (8)
    fwrite($fp, 'XY');
    echo "ftell after write(2): " . ftell($fp) . "\n";

    // Seek to end to get file size
    fseek($fp, 0, SEEK_END);
    echo "file size: " . ftell($fp) . "\n";

    // Read from start
    fseek($fp, 0, SEEK_SET);
    echo "full content: '" . fread($fp, 100) . "'\n";

    // Seek past EOF and write (extends file)
    fseek($fp, 12, SEEK_SET);
    fwrite($fp, 'ZZ');
    echo "ftell after write past old EOF: " . ftell($fp) . "\n";

    fseek($fp, 0, SEEK_SET);
    $content = fread($fp, 100);
    echo "length after extension: " . strlen($content) . "\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
ftell after write(10): 10
ftell after fseek(5): 5
read(3): 'FGH'
ftell after read(3): 8
ftell after write(2): 10
file size: 10
full content: 'ABCDEFGHXY'
ftell after write past old EOF: 14
length after extension: 14
End
