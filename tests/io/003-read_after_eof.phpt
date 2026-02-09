--TEST--
Read after EOF returns zero immediately (sync completion)
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $php = getenv('TEST_PHP_EXECUTABLE');
    if ($php === false) {
        die("skip no php executable defined");
    }

    $process = proc_open(
        [$php, "-r", "echo 'data';"],
        [1 => ["pipe", "w"]],
        $pipes
    );

    if (!is_resource($process)) {
        echo "Failed to create process\n";
        return "fail";
    }

    $reader = $pipes[1];

    $first = fread($reader, 1024);
    echo "First read: '$first'\n";

    $eof_check = feof($reader);
    echo "EOF after first read: " . ($eof_check ? "yes" : "no") . "\n";

    // Read again - should get EOF
    $second = fread($reader, 1024);
    echo "Second read length: " . strlen($second) . "\n";
    echo "EOF after second read: " . (feof($reader) ? "yes" : "no") . "\n";

    // Third read after EOF - should be instant (sync completion, no suspend)
    $third = fread($reader, 1024);
    echo "Third read length: " . strlen($third) . "\n";

    fclose($reader);
    proc_close($process);
    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
First read: 'data'
EOF after first read: no
Second read length: 0
EOF after second read: yes
Third read length: 0
Result: done
End
