--TEST--
FileSystemWatcher - multiple events from different files
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_multi_' . getmypid();
@mkdir($dir, 0777, true);

$watcher = new FileSystemWatcher($dir, coalesce: false);

spawn(function() use ($dir, $watcher) {
    delay(50);
    file_put_contents($dir . '/a.txt', '1');
    delay(50);
    file_put_contents($dir . '/b.txt', '2');
    delay(50);
    file_put_contents($dir . '/c.txt', '3');
    delay(100);
    $watcher->close();
});

$count = 0;
$allEvents = true;
foreach ($watcher as $event) {
    if (!($event instanceof FileSystemEvent)) {
        $allEvents = false;
    }
    $count++;
}

var_dump($allEvents);
var_dump($count >= 3);

// Cleanup
@unlink($dir . '/a.txt');
@unlink($dir . '/b.txt');
@unlink($dir . '/c.txt');
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
