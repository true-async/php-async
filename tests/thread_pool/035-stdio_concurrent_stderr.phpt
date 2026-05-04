--TEST--
ThreadPool: concurrent fwrite(STDERR) from multiple workers — no message corruption
--DESCRIPTION--
Each worker has its own per-thread php_stream wrapping fd 2.
Short writes (<= PIPE_BUF) hit a shared kernel fd but are atomic per POSIX,
so messages must arrive intact (any order, no interleaving inside a message).
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
use Async\ThreadPool;
use function Async\spawn;
use function Async\await_all_or_fail;

spawn(function() {
    $pool = new ThreadPool(4);
    $futures = [];
    for ($i = 0; $i < 16; $i++) {
        $futures[] = $pool->submit(static function () use ($i): bool {
            $msg = sprintf("MARK[%02d]\n", $i);
            return fwrite(STDERR, $msg) === strlen($msg);
        });
    }
    foreach (await_all_or_fail($futures) as $ok) {
        if (!$ok) { echo "FAIL\n"; return; }
    }
    $pool->close();
    fprintf(STDOUT, "main: done\n");
});
?>
--EXPECTF--
MARK[%d]
%A
main: done
