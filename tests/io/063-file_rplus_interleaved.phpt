--TEST--
r+ mode: interleaved reads and writes maintain correct position
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, '0123456789');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r+');

    // Position starts at 0
    echo "ftell on open: " . ftell($fp) . "\n";

    // Read 3 bytes
    $data = fread($fp, 3);
    echo "read(3): '$data'\n";
    echo "ftell after read(3): " . ftell($fp) . "\n";

    // Write 2 bytes in the middle (overwrites bytes 3-4)
    fwrite($fp, 'AB');
    echo "ftell after write(2): " . ftell($fp) . "\n";

    // Read 2 more bytes
    $data = fread($fp, 2);
    echo "read(2): '$data'\n";
    echo "ftell after read(2): " . ftell($fp) . "\n";

    // Seek to beginning and read everything
    fseek($fp, 0, SEEK_SET);
    $all = fread($fp, 100);
    echo "full content: '$all'\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
read(3): '012'
ftell after read(3): 3
ftell after write(2): 5
read(2): '56'
ftell after read(2): 7
full content: '012AB56789'
End
