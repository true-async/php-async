--TEST--
proc_close() returns the child's exit code, including 128, when waiting in a coroutine
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
if (DIRECTORY_SEPARATOR === '\\') die('skip Unix-only test');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// 128 is the only code in 0..255 that decodes wrongly when a decoded exit code is treated as a
// raw wait status (zero low seven bits, WEXITSTATUS gives 0); 0, 1 and 255 are sanity cases.
foreach ([0, 1, 128, 255] as $code) {
    $c = spawn(function () use ($code) {
        $process = proc_open(['sh', '-c', "exit $code"], [], $pipes);
        return proc_close($process);
    });
    echo "$code: ", await($c), "\n";
}

echo "Done\n";
?>
--EXPECT--
0: 0
1: 1
128: 128
255: 255
Done
