--TEST--
watch_filesystem() - detects file rename
--FILE--
<?php

use Async\FileSystemEvent;
use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\watch_filesystem;

$dir = sys_get_temp_dir() . '/async_watch_rename_' . getmypid();
@mkdir($dir, 0777, true);

// Create the file first
$src = $dir . '/original.txt';
file_put_contents($src, 'content');

$future = watch_filesystem($dir);

spawn(function() use ($dir, $src) {
    delay(50);
    rename($src, $dir . '/renamed.txt');
});

$event = await($future);

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
