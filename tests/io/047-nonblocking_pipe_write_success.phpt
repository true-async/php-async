--TEST--
Non-blocking pipe write succeeds and data is received by reader
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

    // Child echoes back whatever it reads from stdin
    $process = proc_open(
        [$php, "-r", "echo stream_get_contents(STDIN);"],
        [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // Set stdin to non-blocking
    stream_set_blocking($pipes[0], false);

    $written = fwrite($pipes[0], "non-blocking-data");
    echo "bytes written: $written\n";
    fclose($pipes[0]);

    // Read back from child (blocking is fine here)
    $output = stream_get_contents($pipes[1]);
    echo "child output: '$output'\n";

    fclose($pipes[1]);
    proc_close($process);
});

await($coroutine);
echo "Done\n";

?>
--EXPECT--
Start
bytes written: 17
child output: 'non-blocking-data'
Done
