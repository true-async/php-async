--TEST--
Pipe remains usable after read timeout
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

    // Child waits 200ms then writes
    $process = proc_open(
        [$php, "-r", "usleep(200000); echo \"hello\\n\";"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // First read: 50ms timeout — should timeout
    stream_set_timeout($pipes[1], 0, 50000);
    $line = fgets($pipes[1]);
    $meta = stream_get_meta_data($pipes[1]);
    echo "First read: " . var_export($line, true) . "\n";
    echo "First timed_out: " . var_export($meta['timed_out'], true) . "\n";

    // Second read: 1s timeout — should succeed (data arrives after ~200ms)
    stream_set_timeout($pipes[1], 1);
    $line = fgets($pipes[1]);
    $meta = stream_get_meta_data($pipes[1]);
    echo "Second read: " . var_export(trim($line), true) . "\n";
    echo "Second timed_out: " . var_export($meta['timed_out'], true) . "\n";

    fclose($pipes[0]);
    fclose($pipes[1]);
    proc_close($process);

    return "done";
});

$result = await($coroutine);
echo "Result: $result\n";
echo "End\n";

?>
--EXPECT--
Start
First read: false
First timed_out: true
Second read: 'hello'
Second timed_out: false
Result: done
End
