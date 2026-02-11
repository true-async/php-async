--TEST--
Writing to STDERR in async context does not produce IO error
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
use function Async\await;

$c = spawn(function() {
    fwrite(STDERR, "error message from coroutine\n");
    fwrite(STDOUT, "stdout ok\n");
    return "done";
});

$result = await($c);
fwrite(STDOUT, "result: $result\n");
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

echo "STDOUT: $stdout";
echo "STDERR: $stderr";
echo "Exit: $exit\n";

?>
--EXPECT--
STDOUT: stdout ok
result: done
STDERR: error message from coroutine
Exit: 0
