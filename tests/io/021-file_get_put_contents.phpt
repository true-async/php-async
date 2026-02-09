--TEST--
file_get_contents and file_put_contents in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    // file_put_contents uses plain_wrapper write path
    $written = file_put_contents($tmpfile, "async content here");
    echo "Written: $written\n";

    // file_get_contents uses plain_wrapper read path
    $data = file_get_contents($tmpfile);
    echo "Read: '$data'\n";

    // Append mode
    $written2 = file_put_contents($tmpfile, " + appended", FILE_APPEND);
    echo "Appended: $written2\n";

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
Written: 18
Read: 'async content here'
Appended: 11
Final: 'async content here + appended'
Result: done
End
