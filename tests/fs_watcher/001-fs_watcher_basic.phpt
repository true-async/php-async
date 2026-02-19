--TEST--
FileSystemWatcher - basic file change detection via foreach
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_test_' . getmypid();
@mkdir($dir, 0777, true);

$watcher = new FileSystemWatcher($dir);
var_dump($watcher instanceof FileSystemWatcher);
var_dump(!$watcher->isClosed());

spawn(function() use ($dir, $watcher) {
    delay(50);
    file_put_contents($dir . '/test.txt', 'hello');
    delay(100);
    $watcher->close();
});

$count = 0;
$allValid = true;
foreach ($watcher as $event) {
    if (!($event instanceof FileSystemEvent)) $allValid = false;
    if (!is_string($event->path)) $allValid = false;
    if (!($event->renamed || $event->changed)) $allValid = false;
    $count++;
}

var_dump($allValid);
var_dump($count >= 1);
var_dump($watcher->isClosed());

// Cleanup
@unlink($dir . '/test.txt');
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
done
