--TEST--
More coroutines waiting for one file lock than the thread pool has threads
--DESCRIPTION--
Eight coroutines lock one file with the libuv thread pool pinned to four threads: each
writes one line while holding the lock, and all eight lines must appear. A waiter must not
occupy a pool thread. Reads and writes of a regular file are uv_fs requests on that same
pool, so waiters that fill it leave the lock holder's own write with no thread to run on,
and the lock is never released. A regression therefore shows as a hang with no output at
all, not as a wrong count of lines. The lock itself is checked too: every waiter fails
loudly if flock() returns false, and each compares the file size across its own suspension
so that a lock granted to two coroutines at once is reported rather than appended over.
true-async/php-async#221.
--ENV--
UV_THREADPOOL_SIZE=4
--FILE--
<?php

use function Async\await_all;
use function Async\delay;
use function Async\spawn;

const WAITERS = 8;

$path = __DIR__ . '/084-flock.tmp';
@unlink($path);

$tasks = [];

for ($i = 0; $i < WAITERS; $i++) {
    $tasks[] = spawn(function () use ($i, $path) {
        $fh = fopen($path, 'a');

        if (!flock($fh, LOCK_EX)) {
            echo "waiter $i: flock failed\n";
            return;
        }

        // Held across a suspension point, so that the waiters really do overlap:
        // without it each coroutine could take and drop the lock before the next runs.
        clearstatcache(true, $path);
        $size = filesize($path);
        delay(1);
        clearstatcache(true, $path);

        if (filesize($path) !== $size) {
            echo "waiter $i: another writer while the lock was held\n";
        }

        fwrite($fh, "waiter $i\n");
        fflush($fh);

        flock($fh, LOCK_UN);
        fclose($fh);
    });
}

await_all($tasks);

echo "lines: ", count(file($path)), "\n";
echo "ok\n";
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/084-flock.tmp');
?>
--EXPECT--
lines: 8
ok
