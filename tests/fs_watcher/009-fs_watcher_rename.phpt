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

$src = $dir . '/original.txt';
$dst = $dir . '/renamed.txt';

$watcher = new FileSystemWatcher($dir);

spawn(function() use ($src, $dst, $watcher) {
    delay(50);
    file_put_contents($src, 'content');
    delay(100);
    rename($src, $dst);
    delay(150);
    $watcher->close();
});

$event = null;
foreach ($watcher as $e) {
    // On macOS (kqueue), file creation also fires as UV_RENAME.
    // Skip events until the rename has actually happened.
    if (file_exists($dst)) {
        $event = $e;
        break;
    }
}

$watcher->close();

var_dump($event instanceof FileSystemEvent);
echo "renamed: " . var_export($event->renamed, true) . "\n";
echo "path matches: " . var_export($event->path === $dir, true) . "\n";

// Cleanup
@unlink($dst);
@unlink($src);
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
renamed: true
path matches: true
done
