--TEST--
proc_close wakes parked fread on child stdout pipe (regression: #144)
--SKIPIF--
<?php
if (!function_exists("proc_open")) echo "skip proc_open() is not available";
$php = getenv('TEST_PHP_EXECUTABLE');
if ($php === false) echo "skip no php executable defined";
?>
--FILE--
<?php
// libuv_io_close used to call uv_read_stop without notifying the parked
// reader on the IO event. Stream owner (proc_open's resource dtor) then
// pefree'd the stream while the reader was still parked — reader hung
// forever, deadlock detector aborted, and an eventual resume UAFed on the
// freed stream. The fix: libuv_io_close now NOTIFYs subscribers with an
// io_closed marker; php_stdiop_read early-returns on it without touching
// stream/data.

use function Async\spawn;
use function Async\await_all;
use function Async\delay;

$php = getenv('TEST_PHP_EXECUTABLE');

$proc = proc_open([$php, '-r', 'usleep(200000);'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes);

$r = spawn(function() use ($pipes) {
    $d = @fread($pipes[1], 4096);
    echo "fread returned ", var_export($d, true), "\n";
});

$k = spawn(function() use ($proc) {
    delay(50);
    proc_terminate($proc, 15);
    proc_close($proc);
});

await_all([$r, $k]);
echo "OK\n";
?>
--EXPECT--
fread returned false
OK
