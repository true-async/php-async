--TEST--
Mixed read/write on same file handle (r+ mode) with seek
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "AAAAAAAAAA");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r+');

    // Read first
    $data = fread($fp, 5);
    echo "Read: '$data'\n";

    // Write at current position (offset 5)
    fwrite($fp, "BBBBB");

    // Seek back to start and read all
    fseek($fp, 0, SEEK_SET);
    $all = fread($fp, 1024);
    echo "After write: '$all'\n";

    // Overwrite beginning
    fseek($fp, 0, SEEK_SET);
    fwrite($fp, "CC");

    // Read from position 2
    $rest = fread($fp, 1024);
    echo "After overwrite: '$rest'\n";

    fclose($fp);

    // Verify final content
    $final = file_get_contents($tmpfile);
    echo "Final: '$final'\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Read: 'AAAAA'
After write: 'AAAAABBBBB'
After overwrite: 'AAABBBBB'
Final: 'CCAAABBBBB'
Result: done
End
