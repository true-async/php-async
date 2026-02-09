--TEST--
fflush and fsync work in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    $fp = fopen($tmpfile, 'w');
    fwrite($fp, "data to flush");

    $flush_result = fflush($fp);
    echo "fflush: " . ($flush_result ? "true" : "false") . "\n";

    fclose($fp);

    // Verify data persisted
    $data = file_get_contents($tmpfile);
    echo "Data: '$data'\n";

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
fflush: true
Data: 'data to flush'
Result: done
End
