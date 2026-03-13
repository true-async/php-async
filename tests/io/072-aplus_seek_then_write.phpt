--TEST--
a+ mode: multiple fseek+read cycles, every write always goes to EOF
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'ABCDE');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a+');

    // Read 2 bytes from position 0
    $d = fread($fp, 2);
    echo "read(2): '$d'\n";
    echo "ftell after read(2): " . ftell($fp) . "\n";

    // Write goes to EOF (position 5)
    fwrite($fp, '1');
    echo "ftell after write('1'): " . ftell($fp) . "\n";

    // Seek to 1 and read 2
    fseek($fp, 1, SEEK_SET);
    $d = fread($fp, 2);
    echo "read(2) at pos 1: '$d'\n";
    echo "ftell: " . ftell($fp) . "\n";

    // Write again — must go to current EOF (6)
    fwrite($fp, '2');
    echo "ftell after second write('2'): " . ftell($fp) . "\n";

    // Seek to end and verify
    fseek($fp, 0, SEEK_END);
    echo "EOF position: " . ftell($fp) . "\n";

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
read(2): 'AB'
ftell after read(2): 2
ftell after write('1'): 3
read(2) at pos 1: 'BC'
ftell: 3
ftell after second write('2'): 4
EOF position: 7
full content: 'ABCDE12'
End
