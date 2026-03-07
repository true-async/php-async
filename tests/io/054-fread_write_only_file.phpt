--TEST--
fread on write-only file handle returns false
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w');
    fwrite($fp, "Hello");

    $result = @fread($fp, 1024);
    echo "fread returned: " . var_export($result, true) . "\n";

    fclose($fp);

    // Verify file was written correctly
    echo "Content: '" . file_get_contents($tmpfile) . "'\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
fread returned: false
Content: 'Hello'
Result: done
End
