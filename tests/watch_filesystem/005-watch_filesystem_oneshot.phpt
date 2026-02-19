--TEST--
watch_filesystem() - resolves only on first event (one-shot)
--FILE--
<?php

use Async\FileSystemEvent;
use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\watch_filesystem;

$dir = sys_get_temp_dir() . '/async_watch_oneshot_' . getmypid();
@mkdir($dir, 0777, true);

$future = watch_filesystem($dir);

spawn(function() use ($dir) {
    delay(50);
    // Create first file — should trigger the future
    file_put_contents($dir . '/first.txt', 'one');
    delay(50);
    // Create second file — future already resolved, should be ignored
    file_put_contents($dir . '/second.txt', 'two');
});

$event = await($future);
var_dump($event instanceof FileSystemEvent);

// Small delay to ensure second file write doesn't cause issues
delay(100);

echo "done\n";

// Cleanup
@unlink($dir . '/first.txt');
@unlink($dir . '/second.txt');
@rmdir($dir);
?>
--EXPECT--
bool(true)
done
