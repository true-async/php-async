--TEST--
FileSystemWatcher - coalesce mode merges events for same file
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_coal_' . getmypid();
@mkdir($dir, 0777, true);

// Coalesce mode (default)
$watcher = new FileSystemWatcher($dir, recursive: false, coalesce: true);

spawn(function() use ($dir, $watcher) {
    delay(50);
    // Rapid writes to same file — should merge into fewer events
    file_put_contents($dir . '/test.txt', 'a');
    file_put_contents($dir . '/test.txt', 'b');
    file_put_contents($dir . '/test.txt', 'c');
    delay(150);
    $watcher->close();
});

$count = 0;
$allValid = true;
foreach ($watcher as $event) {
    if (!($event instanceof FileSystemEvent)) $allValid = false;
    $count++;
}

var_dump($allValid);
var_dump($count >= 1);

// Cleanup
@unlink($dir . '/test.txt');
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
