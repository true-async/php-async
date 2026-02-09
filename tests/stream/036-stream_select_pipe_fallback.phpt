--TEST--
stream_select with pipe falls back to regular select on Windows
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Windows') die('skip Windows only');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$coroutine = spawn(function() {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(PHP_BINARY . ' -r "echo \"hello\";"', $descriptors, $pipes);

    if (!is_resource($process)) {
        echo "Failed to open process\n";
        return;
    }

    fclose($pipes[0]);

    // On Windows, async poll for pipes is not supported.
    // stream_select should fall back to regular select() and still work.
    $read = [$pipes[1]];
    $write = null;
    $except = null;

    $result = stream_select($read, $write, $except, 5);
    echo "Select result: $result\n";

    $data = stream_get_contents($pipes[1]);
    echo "Data: '$data'\n";

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
Select result: 1
Data: 'hello'
Result: done
End
