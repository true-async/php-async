--TEST--
stream_select works with pipe streams from proc_open in async context
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

    $process = proc_open(PHP_BINARY . ' -r "echo \"hello from child\";"', $descriptors, $pipes);

    if (!is_resource($process)) {
        echo "Failed to open process\n";
        return;
    }

    fclose($pipes[0]);

    $read = [$pipes[1]];
    $write = null;
    $except = null;

    $result = stream_select($read, $write, $except, 5);
    echo "Select result: $result\n";
    echo "Readable: " . count($read) . "\n";

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
Readable: 1
Data: 'hello from child'
Result: done
End
