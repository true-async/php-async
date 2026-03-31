--TEST--
flock() does not block the event loop in coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Start\n";

$tmpfile = tempnam(sys_get_temp_dir(), 'async_flock_test_');
file_put_contents($tmpfile, "test");

// Take the lock in the main context before spawning coroutines
$main_fp = fopen($tmpfile, 'r');
flock($main_fp, LOCK_EX);
echo "main: locked\n";

$locker = spawn(function() use ($tmpfile) {
    echo "locker: waiting for lock\n";
    $fp = fopen($tmpfile, 'r');
    flock($fp, LOCK_EX);
    echo "locker: acquired\n";
    flock($fp, LOCK_UN);
    fclose($fp);
});

$worker = spawn(function() {
    echo "worker: running\n";
});

// Let coroutines run — worker should complete, locker should be blocked in thread pool
Async\delay(50);

echo "main: unlocking\n";
flock($main_fp, LOCK_UN);
fclose($main_fp);

await($locker);

unlink($tmpfile);
echo "End\n";

?>
--EXPECT--
Start
main: locked
locker: waiting for lock
worker: running
main: unlocking
locker: acquired
End
