--TEST--
Async\signal() #109: ThreadPool workers can register different signals concurrently
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (!function_exists('posix_kill')) echo "skip posix extension required";
if (!PHP_ZTS) echo "skip ZTS required";
?>
--FILE--
<?php
// Each worker waits for a *different* signal. The main thread fires both
// signals; each worker must receive its own and only its own. This
// validates that per-thread libuv signal handles are independent and
// that signal dispatch routes by signo, not by "first registered".

use Async\Signal;
use Async\ThreadPool;
use function Async\signal;
use function Async\spawn;
use function Async\await;
use function Async\timeout;
use function Async\delay;

echo "start\n";

$pool = new ThreadPool(workers: 2);

$f1 = $pool->submit(function () {
    try {
        $r = await(signal(Signal::SIGUSR1), timeout(2000));
        return "w1:" . $r->name;
    } catch (\Throwable $e) {
        return "w1:ex:" . $e->getMessage();
    }
});

$f2 = $pool->submit(function () {
    try {
        $r = await(signal(Signal::SIGUSR2), timeout(2000));
        return "w2:" . $r->name;
    } catch (\Throwable $e) {
        return "w2:ex:" . $e->getMessage();
    }
});

spawn(function () {
    delay(300);
    posix_kill(getmypid(), SIGUSR1);
    posix_kill(getmypid(), SIGUSR2);
});

$results = [await($f1), await($f2)];
sort($results);
foreach ($results as $r) echo $r . "\n";

$pool->close();
echo "end\n";

?>
--EXPECT--
start
w1:SIGUSR1
w2:SIGUSR2
end
