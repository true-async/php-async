--TEST--
FileSystemWatcher - detects file rename
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_rename_' . getmypid();
@mkdir($dir, 0777, true);

// Create the file first
$src = $dir . '/original.txt';
file_put_contents($src, 'content');

$watcher = new FileSystemWatcher($dir);

spawn(function() use ($dir, $src, $watcher) {
    delay(50);
    rename($src, $dir . '/renamed.txt');
    delay(150);
    $watcher->close();
});

$event = null;
foreach ($watcher as $e) {
    $event = $e;
    break;
}

$watcher->close();

var_dump($event instanceof FileSystemEvent);
echo "renamed: " . var_export($event->renamed, true) . "\n";
echo "path matches: " . var_export($event->path === $dir, true) . "\n";

// Cleanup
@unlink($dir . '/renamed.txt');
@unlink($src);
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
renamed: true
path matches: true
done
