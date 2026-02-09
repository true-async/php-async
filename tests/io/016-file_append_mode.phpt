--TEST--
File append mode preserves existing content in async IO
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "original");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'a');
    fwrite($fp, "_appended");
    fclose($fp);

    // Second append
    $fp = fopen($tmpfile, 'a');
    fwrite($fp, "_more");
    fclose($fp);

    // Read back
    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);

    echo "Data: '$data'\n";
    echo "Length: " . strlen($data) . "\n";
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
Data: 'original_appended_more'
Length: 22
Result: done
End
