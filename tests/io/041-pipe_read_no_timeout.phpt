--TEST--
Pipe read without timeout works as before
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
        [$php, "-r", "usleep(50000); echo \"data\\n\";"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // No stream_set_timeout — should wait for data
    $line = fgets($pipes[1]);
    $meta = stream_get_meta_data($pipes[1]);
    echo "Read: " . var_export(trim($line), true) . "\n";
    echo "timed_out: " . var_export($meta['timed_out'], true) . "\n";

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
Read: 'data'
timed_out: false
Result: done
End
