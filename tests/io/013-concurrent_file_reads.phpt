--TEST--
Concurrent file reads from separate coroutines with independent handles
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "SHARED_FILE_CONTENT_FOR_TEST");

$reader1 = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);
    return "r1:" . $data;
});

$reader2 = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');
    $data = fread($fp, 1024);
    fclose($fp);
    return "r2:" . $data;
});

[$results, $exceptions] = await_all([$reader1, $reader2]);

echo $results[0] . "\n";
echo $results[1] . "\n";
echo "Exceptions: " . count($exceptions) . "\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
r1:SHARED_FILE_CONTENT_FOR_TEST
r2:SHARED_FILE_CONTENT_FOR_TEST
Exceptions: 0
End
