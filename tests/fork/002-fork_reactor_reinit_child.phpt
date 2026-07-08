--TEST--
pcntl_fork() under an active reactor: the child gets an independent, working reactor
--EXTENSIONS--
pcntl
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

// Activate the reactor, then settle so only the main coroutine remains.
await(spawn(function () { delay(1); }));

$pid = pcntl_fork();

if ($pid === 0) {
    // Child: it inherited the parent's libuv loop (shared epoll). The fork hook
    // reinitializes it, so async I/O in the child must work on its own reactor.
    $r = await(spawn(function () { delay(1); return 42; }));
    exit($r === 42 ? 0 : 1);
}

$status = 0;
pcntl_waitpid($pid, $status);

echo "child exit code: ", pcntl_wexitstatus($status), "\n";
echo "parent still alive\n";

?>
--EXPECT--
child exit code: 0
parent still alive
