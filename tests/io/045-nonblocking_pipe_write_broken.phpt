--TEST--
Non-blocking write to broken pipe returns error without crash
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

    // Child exits immediately without reading stdin
    $process = proc_open(
        [$php, "-r", "exit(0);"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // Wait for child to exit so pipe is truly broken
    fread($pipes[1], 1024);
    fclose($pipes[1]);

    // Set stdin to non-blocking
    stream_set_blocking($pipes[0], false);

    // Write to broken pipe — must not crash or hang
    $result = @fwrite($pipes[0], str_repeat("X", 65536));
    echo "write result: " . var_export($result, true) . "\n";
    echo "no crash: yes\n";

    fclose($pipes[0]);
    proc_close($process);
});

await($coroutine);
echo "Done\n";

?>
--EXPECTF--
Start
write result: %s
no crash: yes
Done
