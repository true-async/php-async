--TEST--
Async\signal() #109: multiple spawn_thread workers each receive process-directed signal
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') echo "skip Unix-only test";
if (!function_exists('posix_kill')) echo "skip posix extension required";
if (!PHP_ZTS) echo "skip ZTS required";
?>
--FILE--
<?php

use Async\Signal;
use function Async\signal;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\timeout;
use function Async\delay;

echo "start\n";

$t1 = spawn_thread(function () {
    try {
        $r = await(signal(Signal::SIGUSR1), timeout(2000));
        return "t1:" . $r->name;
    } catch (\Throwable $e) {
        return "t1:ex:" . $e->getMessage();
    }
});

$t2 = spawn_thread(function () {
    try {
        $r = await(signal(Signal::SIGUSR1), timeout(2000));
        return "t2:" . $r->name;
    } catch (\Throwable $e) {
        return "t2:ex:" . $e->getMessage();
    }
});

spawn(function () {
    delay(300);
    posix_kill(getmypid(), SIGUSR1);
});

$results = [await($t1), await($t2)];
sort($results);
foreach ($results as $r) echo $r . "\n";

echo "end\n";

?>
--EXPECT--
start
t1:SIGUSR1
t2:SIGUSR1
end
