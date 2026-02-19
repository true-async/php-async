--TEST--
FileSystemWatcher - scope cancellation terminates iteration
--FILE--
<?php

use Async\FileSystemWatcher;
use function Async\spawn;
use function Async\delay;
use function Async\timeout;

$dir = sys_get_temp_dir() . '/async_fsw_cancel_' . getmypid();
@mkdir($dir, 0777, true);

// Watchdog: if test hangs longer than 2s, fail
$watchdog = spawn(function() {
    delay(2000);
    echo "FAIL: test timed out, cancellation did not work\n";
    exit(1);
});

$watcher = new FileSystemWatcher($dir);

// Close watcher after 100ms from another coroutine
spawn(function() use ($watcher) {
    delay(100);
    $watcher->close();
});

$iterated = false;
foreach ($watcher as $event) {
    $iterated = true;
}

// foreach should have exited due to close
echo "iteration ended\n";
var_dump($watcher->isClosed());

$watchdog->cancel();

// Cleanup
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
iteration ended
bool(true)
done
