--TEST--
ftruncate works correctly in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "ABCDEFGHIJ");

    echo "Size before: " . fstat($fp)['size'] . "\n";

    // Truncate to 5 bytes
    ftruncate($fp, 5);
    echo "Size after truncate: " . fstat($fp)['size'] . "\n";

    // Read back
    fseek($fp, 0, SEEK_SET);
    $data = fread($fp, 1024);
    echo "Data: '$data'\n";
    echo "Length: " . strlen($data) . "\n";

    // Truncate to 0
    ftruncate($fp, 0);
    fseek($fp, 0, SEEK_SET);
    $data2 = fread($fp, 1024);
    echo "After zero truncate: " . strlen($data2) . "\n";

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
Size before: 10
Size after truncate: 5
Data: 'ABCDE'
Length: 5
After zero truncate: 0
Result: done
End
