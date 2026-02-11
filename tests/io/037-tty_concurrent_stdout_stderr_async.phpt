--TEST--
Concurrent coroutines writing to STDOUT and STDERR
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) {
    die("skip no php executable defined");
}

$code = <<<'CHILD'
use function Async\spawn;
use function Async\await_all;

$w1 = spawn(function() {
    for ($i = 0; $i < 3; $i++) {
        fwrite(STDOUT, "out:$i\n");
    }
    return "stdout-done";
});

$w2 = spawn(function() {
    for ($i = 0; $i < 3; $i++) {
        fwrite(STDERR, "err:$i\n");
    }
    return "stderr-done";
});

[$results, $exceptions] = await_all([$w1, $w2]);
echo "Results: " . implode(",", $results) . "\n";
CHILD;

$process = proc_open(
    [$php, "-r", $code],
    [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ],
    $pipes
);

if (!is_resource($process)) {
    die("Failed to create process");
}

fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exit = proc_close($process);

// Sort output lines since coroutine ordering is non-deterministic
$stdout_lines = array_filter(explode("\n", trim($stdout)));
sort($stdout_lines);
$stderr_lines = array_filter(explode("\n", trim($stderr)));
sort($stderr_lines);

echo "STDOUT:\n";
foreach ($stdout_lines as $line) echo "$line\n";
echo "STDERR:\n";
foreach ($stderr_lines as $line) echo "$line\n";
echo "Exit: $exit\n";

?>
--EXPECT--
STDOUT:
Results: stdout-done,stderr-done
out:0
out:1
out:2
STDERR:
err:0
err:1
err:2
Exit: 0
