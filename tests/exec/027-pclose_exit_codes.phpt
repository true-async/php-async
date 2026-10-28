--TEST--
pclose() returns the child's exit code at top level and in a coroutine
--SKIPIF--
<?php
if (DIRECTORY_SEPARATOR === '\\') die('skip Unix-only test');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;

foreach ([0, 1, 128] as $code) {
    $h = popen("exit $code", 'r');
    echo "top level $code: ", pclose($h), "\n";
}

$c = spawn(function () {
    $h = popen('echo out; exit 3', 'r');
    $out = fread($h, 100);
    return trim($out) . ' ' . pclose($h);
});
echo "coroutine: ", await($c), "\n";

echo "Done\n";
?>
--EXPECT--
top level 0: 0
top level 1: 1
top level 128: 128
coroutine: out 3
Done
