--TEST--
Thread: isRunning / isCompleted / isCancelled / getResult / getException / cancel / finally
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn_thread;
use function Async\await;

// Covers thread.c:2192-2347 — the PHP method bodies for status accessors,
// finally registration (both "register" and "call immediately" paths) and
// the "Cannot directly construct" guard.

echo "start\n";

// 1. Forbidden direct construction.
try {
    new \Async\Thread();
} catch (\Error $e) {
    echo "construct: " . $e->getMessage() . "\n";
}

// 2. A thread that returns a value.
$ok = spawn_thread(function () {
    return "hello";
});
echo "before await running=" . ($ok->isRunning() ? "T" : "F") . "\n";
$result = await($ok);
echo "result=$result\n";
echo "isCompleted=" . ($ok->isCompleted() ? "T" : "F") . "\n";
echo "isRunning=" . ($ok->isRunning() ? "T" : "F") . "\n";
echo "isCancelled=" . ($ok->isCancelled() ? "T" : "F") . "\n";
var_dump($ok->getResult());
var_dump($ok->getException());

// 3. A thread that throws.
$bad = spawn_thread(function () {
    throw new \RuntimeException("kaboom");
});
try {
    await($bad);
    echo "no error\n";
} catch (\Throwable $e) {
    echo "await caught: " . $e->getMessage() . "\n";
}
var_dump($bad->getException() !== null);

// 4. finally() on an already-completed thread runs the callback immediately.
$ok->finally(function (\Async\Thread $t) {
    echo "late finally fired, completed=" . ($t->isCompleted() ? "T" : "F") . "\n";
});

// 5. cancel() on a completed thread is a no-op.
$ok->cancel();
echo "cancel on completed = no throw\n";

// 6. cancel() on a running thread throws "not yet implemented".
$running = spawn_thread(function () {
    return "soon";
});
try {
    $running->cancel();
} catch (\Throwable $e) {
    echo "cancel running: " . $e->getMessage() . "\n";
}
await($running);

echo "end\n";

?>
--EXPECTF--
start
construct: Call to private Async\Thread::__construct() from global scope
before await running=%s
result=hello
isCompleted=T
isRunning=F
isCancelled=F
string(5) "hello"
NULL
await caught: %s
bool(true)
late finally fired, completed=T
cancel on completed = no throw
cancel running: Thread cancellation is not yet implemented
end
