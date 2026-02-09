--TEST--
stream_get_contents works in async context with offset and maxlength
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "0123456789ABCDEF");

$coroutine = spawn(function() use ($tmpfile) {
    // Full read
    $fp = fopen($tmpfile, 'r');
    $all = stream_get_contents($fp);
    echo "Full: '$all'\n";
    fclose($fp);

    // With maxlength
    $fp = fopen($tmpfile, 'r');
    $part = stream_get_contents($fp, 5);
    echo "Max 5: '$part'\n";
    fclose($fp);

    // With offset
    $fp = fopen($tmpfile, 'r');
    $from8 = stream_get_contents($fp, -1, 8);
    echo "From 8: '$from8'\n";
    fclose($fp);

    // With both maxlength and offset
    $fp = fopen($tmpfile, 'r');
    $slice = stream_get_contents($fp, 4, 10);
    echo "Slice(10,4): '$slice'\n";
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
Full: '0123456789ABCDEF'
Max 5: '01234'
From 8: '89ABCDEF'
Slice(10,4): 'ABCD'
Result: done
End
