--TEST--
readfile and fpassthru work in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "readfile output");

$coroutine = spawn(function() use ($tmpfile) {
    // readfile
    echo "readfile: ";
    $bytes = readfile($tmpfile);
    echo "\nBytes: $bytes\n";

    // fpassthru — read partial, then passthru rest
    file_put_contents($tmpfile, "HEAD_TAIL_DATA");
    $fp = fopen($tmpfile, 'r');
    $head = fread($fp, 5);
    echo "Head: '$head'\n";
    echo "Passthru: ";
    $rest = fpassthru($fp);
    echo "\nPassthru bytes: $rest\n";
    fclose($fp);

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
readfile: readfile output
Bytes: 15
Head: 'HEAD_'
Passthru: TAIL_DATA
Passthru bytes: 9
Result: done
End
