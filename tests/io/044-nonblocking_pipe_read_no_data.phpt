--TEST--
Non-blocking pipe read returns immediately when no data available
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

    // Child sleeps — no data on stdout for a while
    $process = proc_open(
        [$php, "-r", "usleep(500000); echo 'late';"],
        [1 => ["pipe", "w"]],
        $pipes
    );

    if (!is_resource($process)) {
        return "fail";
    }

    // Set non-blocking BEFORE reading
    stream_set_blocking($pipes[1], false);

    $start = hrtime(true);
    $result = fread($pipes[1], 1024);
    $elapsed_ms = (hrtime(true) - $start) / 1_000_000;

    echo "fread returned: " . var_export($result, true) . "\n";
    // Must return immediately (well under 100ms), not hang for 500ms
    echo "returned quickly: " . ($elapsed_ms < 100 ? "yes" : "no") . "\n";
    echo "eof: " . (feof($pipes[1]) ? "yes" : "no") . "\n";

    fclose($pipes[1]);
    proc_close($process);
});

await($coroutine);
echo "Done\n";

?>
--EXPECT--
Start
fread returned: ''
returned quickly: yes
eof: no
Done
