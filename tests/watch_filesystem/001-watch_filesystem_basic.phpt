--TEST--
watch_filesystem() - basic file change detection
--FILE--
<?php

use Async\Future;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\await;
use function Async\delay;
use function Async\watch_filesystem;

$dir = sys_get_temp_dir() . '/async_watch_test_' . getmypid();
@mkdir($dir, 0777, true);

$future = watch_filesystem($dir);
var_dump($future instanceof Future);

spawn(function() use ($dir) {
    delay(50);
    file_put_contents($dir . '/test.txt', 'hello');
});

$event = await($future);

var_dump($event instanceof FileSystemEvent);
var_dump($event->path === $dir);
var_dump(is_string($event->filename) || $event->filename === null);
var_dump(is_bool($event->renamed));
var_dump(is_bool($event->changed));

// At least one flag must be set
var_dump($event->renamed || $event->changed);

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
bool(true)
bool(true)
done
