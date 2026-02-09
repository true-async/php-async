--TEST--
Writing to read-only file handle fails gracefully
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');
file_put_contents($tmpfile, "existing data");

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'r');

    // Attempt to write to read-only handle
    $result = @fwrite($fp, "should not work");
    echo "Write result: " . var_export($result, true) . "\n";

    // Verify original data is intact
    fseek($fp, 0, SEEK_SET);
    $data = fread($fp, 1024);
    echo "Data intact: " . ($data === "existing data" ? "yes" : "no") . "\n";

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
Write result: false
Data intact: yes
Result: done
End
