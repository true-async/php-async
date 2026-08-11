--TEST--
Thread: a fatal error disposes live Thread events (no leftover libuv notify handles)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--INI--
memory_limit=64M
--FILE--
<?php
/*
 * Regression: php_error_cb marks every live object as destructed before it
 * bails out, so dtor_obj never runs for a Thread still held by a variable.
 * Thread released its event — and with it closed the uv_async notify handle —
 * only from dtor_obj, so after any fatal error those handles stayed open:
 * uv_loop_close returned EBUSY and the persistent thread context leaked.
 *
 * A debug build names every survivor on stderr ("leftover libuv handle"),
 * which run-tests folds into the compared output, so the bug appears as extra
 * lines behind the fatal error. Hence no trailing %A here.
 */
use function Async\spawn_thread;
use function Async\await_all;

$threads = [];

for ($i = 0; $i < 4; $i++) {
    $threads[] = spawn_thread(static fn() => bin2hex(random_bytes(8)));
}

await_all($threads);
echo "threads joined\n";

// $threads stays live: the Thread objects are still reachable at bailout.
$data = [];

while (true) {
    $data[] = str_repeat('A', 1024 * 1024);
}
--EXPECTF--
threads joined

Fatal error: Allowed memory size of %d bytes exhausted%sin %s on line %d
