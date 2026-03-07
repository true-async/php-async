--TEST--
stream_get_line reads up to delimiter in async context
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_io_test_');

$coroutine = spawn(function() use ($tmpfile) {
    file_put_contents($tmpfile, "key1=value1;key2=value2;key3=value3");

    $fp = fopen($tmpfile, 'r');

    $line = stream_get_line($fp, 1024, ";");
    echo "First: '$line'\n";

    $line = stream_get_line($fp, 1024, ";");
    echo "Second: '$line'\n";

    $line = stream_get_line($fp, 1024, ";");
    echo "Third: '$line'\n";

    // After all data read
    $line = stream_get_line($fp, 1024, ";");
    echo "EOF: " . var_export($line, true) . "\n";

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
First: 'key1=value1'
Second: 'key2=value2'
Third: 'key3=value3'
EOF: false
Result: done
End
