--TEST--
ftruncate() does not move the file position; writes after truncate go to correct offset
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');

    fwrite($fp, '0123456789');
    echo "ftell after write(10): " . ftell($fp) . "\n";

    // Truncate to 5 bytes — position must NOT change
    ftruncate($fp, 5);
    echo "ftell after ftruncate(5): " . ftell($fp) . "\n";

    // Seek to end and verify size
    fseek($fp, 0, SEEK_END);
    echo "ftell at SEEK_END after truncate: " . ftell($fp) . "\n";

    // Seek to 3, write — must go to position 3
    fseek($fp, 3, SEEK_SET);
    fwrite($fp, 'XY');
    echo "ftell after write(2) at pos 3: " . ftell($fp) . "\n";

    // Read full content
    fseek($fp, 0, SEEK_SET);
    echo "content: '" . fread($fp, 100) . "'\n";

    fclose($fp);
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell after write(10): 10
ftell after ftruncate(5): 10
ftell at SEEK_END after truncate: 5
ftell after write(2) at pos 3: 5
content: '012XY'
End
