--TEST--
FileSystemWatcher - raw mode delivers every event separately
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_raw_' . getmypid();
@mkdir($dir, 0777, true);

// Raw mode
$watcher = new FileSystemWatcher($dir, recursive: false, coalesce: false);

spawn(function() use ($dir, $watcher) {
    delay(50);
    file_put_contents($dir . '/file1.txt', 'hello');
    delay(50);
    file_put_contents($dir . '/file2.txt', 'world');
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
var_dump($count >= 2);

// Cleanup
@unlink($dir . '/file1.txt');
@unlink($dir . '/file2.txt');
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
