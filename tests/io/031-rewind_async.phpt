--TEST--
rewind() works correctly in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w+');
    fwrite($fp, "REWIND_TEST_DATA");

    echo "Pos after write: " . ftell($fp) . "\n";

    rewind($fp);
    echo "Pos after rewind: " . ftell($fp) . "\n";

    $data = fread($fp, 1024);
    echo "Data: '$data'\n";

    // Rewind again and read partial
    rewind($fp);
    $partial = fread($fp, 6);
    echo "Partial: '$partial'\n";
    echo "Pos: " . ftell($fp) . "\n";

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
Pos after write: 16
Pos after rewind: 0
Data: 'REWIND_TEST_DATA'
Partial: 'REWIND'
Pos: 6
Result: done
End
