--TEST--
fseek() in 'a' mode moves ftell position but writes always go to EOF
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, 'HELLO');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a');
    echo "ftell on open: " . ftell($fp) . "\n";

    fwrite($fp, 'AAA');
    echo "ftell after write: " . ftell($fp) . "\n";

    // fseek succeeds and moves the position for ftell
    $r = fseek($fp, 0, SEEK_SET);
    echo "fseek(0) result: $r\n";
    echo "ftell after fseek(0): " . ftell($fp) . "\n";

    $r = fseek($fp, 2, SEEK_SET);
    echo "fseek(2) result: $r\n";
    echo "ftell after fseek(2): " . ftell($fp) . "\n";

    // SEEK_END returns the real file size
    $r = fseek($fp, 0, SEEK_END);
    echo "fseek(END) result: $r\n";
    echo "ftell after fseek(END): " . ftell($fp) . "\n";

    // Write still goes to EOF regardless of previous seeks
    fwrite($fp, 'BBB');
    echo "ftell after second write: " . ftell($fp) . "\n";

    fclose($fp);

    // Verify data integrity: both writes appended, nothing overwritten
    echo "content: '" . file_get_contents($tmpfile) . "'\n";
});

await($coroutine);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
ftell on open: 0
ftell after write: 3
fseek(0) result: 0
ftell after fseek(0): 0
fseek(2) result: 0
ftell after fseek(2): 2
fseek(END) result: 0
ftell after fseek(END): 8
ftell after second write: 11
content: 'HELLOAAABBB'
End
