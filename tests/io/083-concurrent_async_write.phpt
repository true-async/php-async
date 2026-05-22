--TEST--
Concurrent async writes to one descriptor do not corrupt the heap
--DESCRIPTION--
Regression test. Every coroutine writing the same descriptor parks on the
shared async-IO event, so any single write's completion notifies them all.
A spuriously woken coroutine must re-suspend until its OWN request finished
— otherwise it disposes a request whose libuv write is still in flight and
libuv later writes into freed memory (heap corruption / SEGV in
uv__async_io with a NULL callback). php_stdiop_write now loops on
req->completed. Found by the fuzzy-tests io chaos work.
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

const WORKERS = 4;
const LINES   = 250;

$path = __DIR__ . '/083-concurrent.tmp';
$fh = fopen($path, 'w');

// Every coroutine writes the SAME file handle — they all park on its one
// shared async-IO event, which is exactly the spurious-wakeup path.
$done = 0;
$tasks = [];
for ($w = 0; $w < WORKERS; $w++) {
    $tasks[] = spawn(function () use ($w, $fh, &$done) {
        for ($i = 0; $i < LINES; $i++) {
            fwrite($fh, "worker $w line $i ........................\n");
        }
        $done++;
    });
}
await_all($tasks);
fclose($fh);

echo "workers done: $done\n";
echo "lines written: ", count(file($path)), "\n";
echo "ok\n";
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/083-concurrent.tmp');
?>
--EXPECT--
workers done: 4
lines written: 1000
ok
