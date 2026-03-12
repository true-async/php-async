--TEST--
fseek with SEEK_END positions correctly in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "ABCDEFGHIJ");

    // Seek to end
    fseek($fp, 0, SEEK_END);
    echo "ftell at end: " . ftell($fp) . "\n";

    // Seek 3 bytes before end
    fseek($fp, -3, SEEK_END);
    echo "ftell at -3 from end: " . ftell($fp) . "\n";
    $data = fread($fp, 3);
    echo "Read: '$data'\n";

    // Seek 5 bytes before end, read 2
    fseek($fp, -5, SEEK_END);
    $data = fread($fp, 2);
    echo "Read at -5: '$data'\n";

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
ftell at end: 10
ftell at -3 from end: 7
Read: 'HIJ'
Read at -5: 'FG'
Result: done
End
