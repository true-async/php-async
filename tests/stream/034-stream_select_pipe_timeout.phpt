--TEST--
stream_select with pipe streams times out correctly in async context
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

    // Process that sleeps, so pipe won't be readable immediately
    $process = proc_open(PHP_BINARY . ' -r "sleep(10);"', $descriptors, $pipes);

    if (!is_resource($process)) {
        echo "Failed to open process\n";
        return;
    }

    fclose($pipes[0]);

    $read = [$pipes[1]];
    $write = null;
    $except = null;

    // Short timeout — should return 0 (no streams ready)
    $start = microtime(true);
    $result = stream_select($read, $write, $except, 0, 100000); // 100ms
    $elapsed = microtime(true) - $start;

    echo "Select result: $result\n";
    echo "Readable: " . count($read) . "\n";
    echo "Elapsed < 1s: " . ($elapsed < 1.0 ? "yes" : "no") . "\n";

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
Select result: 0
Readable: 0
Elapsed < 1s: yes
Result: done
End
