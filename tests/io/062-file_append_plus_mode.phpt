--TEST--
fopen('a+') allows reads and writes; ftell tracks position; writes always go to EOF
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'HELLO');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a+');

    // On open in a+ mode ftell must be 0 (POSIX: position at start for reads).
    echo "ftell on open: " . ftell($fp) . "\n";

    // Read from position 0 — should get the existing content.
    $data = fread($fp, 5);
    echo "read: '$data'\n";
    echo "ftell after read(5): " . ftell($fp) . "\n";

    // Write always appends to EOF regardless of read position.
    fwrite($fp, '_WORLD');
    echo "ftell after write: " . ftell($fp) . "\n";

    // Seek to 0 and read the whole file.
    fseek($fp, 0, SEEK_SET);
    $all = fread($fp, 100);
    echo "full content: '$all'\n";

    // Another write after seek — must still go to EOF.
    fwrite($fp, '!');
    echo "ftell after second write: " . ftell($fp) . "\n";

    fclose($fp);

    echo "file: '" . file_get_contents($tmpfile) . "'\n";
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
read: 'HELLO'
ftell after read(5): 5
ftell after write: 11
full content: 'HELLO_WORLD'
ftell after second write: 12
file: 'HELLO_WORLD!'
End
