--TEST--
proc_open() handle reuse UAF regression - concurrent rapid proc_close
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) echo "skip no php executable defined";
?>
--FILE--
<?php
// Regression test for UAF in libuv_new_process_event.
//
// Root cause: libuv_process_event_dispose freed the event but left a dangling
// pointer in ASYNC_G(process_events). When the OS reused the same process
// HANDLE for a new process, libuv_new_process_event found the stale hash entry
// and called ZEND_ASYNC_EVENT_ADD_REF on freed memory.
//
// Reproduce: spawn many coroutines doing rapid proc_open/proc_close so that
// the OS HANDLE pool cycles and reuses values quickly.

use function Async\spawn;
use function Async\await_all;

$php = getenv('TEST_PHP_EXECUTABLE');
$coroutines = [];

for ($i = 0; $i < 20; $i++) {
    $coroutines[] = spawn(function() use ($php) {
        $process = proc_open(
            [$php, '-r', 'exit(0);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    });
}

await_all($coroutines);

echo "OK\n";
?>
--EXPECT--
OK
