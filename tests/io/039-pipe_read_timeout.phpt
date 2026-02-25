--TEST--
stream_set_timeout() on pipe read with async IO
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
        [$php, "-r", "sleep(1); echo 'late';"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    stream_set_timeout($pipes[1], 0, 100000); // 100ms timeout

    $line = fgets($pipes[1]);
    $meta = stream_get_meta_data($pipes[1]);

    echo "fgets result: " . var_export($line, true) . "\n";
    echo "timed_out: " . var_export($meta['timed_out'], true) . "\n";
    echo "eof: " . var_export($meta['eof'], true) . "\n";

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
fgets result: false
timed_out: true
eof: false
Result: done
End
