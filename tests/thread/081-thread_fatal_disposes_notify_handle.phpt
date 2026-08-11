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
 * A fatal error bails out without dtor_obj, so a Thread still held by a
 * variable must release its uv_async notify handle from free_obj. An open
 * handle fails uv_loop_close() and leaks the persistent thread context.
 *
 * A debug build prints one "leftover libuv handle" line per survivor on
 * stderr, and run-tests compares stderr together with stdout, so the leak
 * appears as extra lines after the fatal error. No trailing %A for that
 * reason: it would match them.
 */
use function Async\spawn_thread;
use function Async\await_all;

$threads = [];

for ($i = 0; $i < 4; $i++) {
    $threads[] = spawn_thread(static fn() => bin2hex(random_bytes(8)));
}

await_all($threads);
echo "threads joined\n";

// $threads is never unset: the Thread objects must be reachable at bailout.
$data = [];

while (true) {
    $data[] = str_repeat('A', 1024 * 1024);
}
--EXPECTF--
threads joined

Fatal error: Allowed memory size of %d bytes exhausted%sin %s on line %d
