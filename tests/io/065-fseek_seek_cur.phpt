--TEST--
SEEK_CUR relative seeks maintain correct position through reads and writes
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, '0123456789');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r+');

    // Skip 2 bytes forward from start
    fseek($fp, 2, SEEK_CUR);
    echo "ftell after SEEK_CUR(+2): " . ftell($fp) . "\n";

    // Read 3 bytes
    $data = fread($fp, 3);
    echo "read(3): '$data'\n";
    echo "ftell after read(3): " . ftell($fp) . "\n";

    // Skip 1 byte forward
    fseek($fp, 1, SEEK_CUR);
    echo "ftell after SEEK_CUR(+1): " . ftell($fp) . "\n";

    // Step back 2 bytes
    fseek($fp, -2, SEEK_CUR);
    echo "ftell after SEEK_CUR(-2): " . ftell($fp) . "\n";

    // Read 1 byte
    $data = fread($fp, 1);
    echo "read(1): '$data'\n";

    // Write via SEEK_CUR=0 (no-move) then write
    fseek($fp, 0, SEEK_CUR);
    fwrite($fp, 'X');
    echo "ftell after write(1): " . ftell($fp) . "\n";

    // Read full content
    fseek($fp, 0, SEEK_SET);
    echo "full content: '" . fread($fp, 100) . "'\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell after SEEK_CUR(+2): 2
read(3): '234'
ftell after read(3): 5
ftell after SEEK_CUR(+1): 6
ftell after SEEK_CUR(-2): 4
read(1): '4'
ftell after write(1): 6
full content: '01234X6789'
End
