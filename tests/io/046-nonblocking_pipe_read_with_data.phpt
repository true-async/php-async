--TEST--
Non-blocking pipe read returns available data immediately
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

    // Child writes data immediately
    $process = proc_open(
        [$php, "-r", "echo 'hello async';"],
        [1 => ["pipe", "w"]],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // Set non-blocking, then poll for data with retries
    // (CI runners may be slow to start the child process)
    stream_set_blocking($pipes[1], false);

    $data = '';
    for ($i = 0; $i < 10; $i++) {
        usleep(50000); // 50ms
        $chunk = fread($pipes[1], 1024);
        if ($chunk !== '' && $chunk !== false) {
            $data = $chunk;
            break;
        }
    }
    echo "read: '$data'\n";
    echo "has data: " . ($data !== '' && $data !== false ? "yes" : "no") . "\n";

    fclose($pipes[1]);
    proc_close($process);
});

await($coroutine);
echo "Done\n";

?>
--EXPECT--
Start
read: 'hello async'
has data: yes
Done
