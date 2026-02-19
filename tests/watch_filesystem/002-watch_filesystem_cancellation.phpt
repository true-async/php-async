--TEST--
watch_filesystem() - cancellation via timeout
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\timeout;
use function Async\watch_filesystem;

$dir = sys_get_temp_dir() . '/async_watch_cancel_' . getmypid();
@mkdir($dir, 0777, true);

// Watchdog: if test hangs longer than 2s, fail
$watchdog = spawn(function() {
    delay(2000);
    echo "FAIL: test timed out, cancellation did not work\n";
    exit(1);
});

// Watch with a short timeout (50ms), no file will be created
$future = watch_filesystem($dir, false, timeout(50));

try {
    $event = await($future);
    echo "unexpected success\n";
} catch (\Async\TimeoutException $e) {
    echo "timeout caught\n";
} catch (\Cancellation $e) {
    echo "cancellation caught: " . $e->getMessage() . "\n";
}

$watchdog->cancel();

// Cleanup
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
timeout caught
done
