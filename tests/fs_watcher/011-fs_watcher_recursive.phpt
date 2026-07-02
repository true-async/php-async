--TEST--
FileSystemWatcher - recursive watch detects changes in nested subdirectories
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$root = sys_get_temp_dir() . '/async_fsw_rec_' . getmypid();
$deep = $root . '/sub/deep';
@mkdir($deep, 0777, true);

// Recursive watch on the ROOT; the change happens two levels down. On Linux this
// exercises the per-directory emulation (inotify is not recursive); on macOS /
// Windows the native recursive backend.
$watcher = new FileSystemWatcher($root, recursive: true);

spawn(function () use ($deep, $watcher) {
    delay(100);
    file_put_contents($deep . '/nested.txt', 'hello');
    delay(300);
    $watcher->close();
});

$sawNested    = false;
$nestedPathOk = false;

foreach ($watcher as $event) {
    if (!($event instanceof FileSystemEvent)) {
        continue;
    }
    if (str_contains((string) $event->filename, 'nested.txt')) {
        $sawNested    = true;
        $nestedPathOk = ($event->path === $root);
        break;
    }
}

$watcher->close();

var_dump($sawNested);       // nested change seen -> recursion works
var_dump($nestedPathOk);    // event carries the watched root as its path

// Cleanup
@unlink($deep . '/nested.txt');
@rmdir($deep);
@rmdir($root . '/sub');
@rmdir($root);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
