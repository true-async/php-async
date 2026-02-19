--TEST--
FileSystemWatcher - events buffered while no one iterates
--FILE--
<?php

use Async\FileSystemWatcher;
use Async\FileSystemEvent;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_buf_' . getmypid();
@mkdir($dir, 0777, true);

$watcher = new FileSystemWatcher($dir, coalesce: false);

spawn(function() use ($dir, $watcher) {
    file_put_contents($dir . '/early1.txt', 'data');
    delay(30);
    file_put_contents($dir . '/early2.txt', 'data');
    delay(100);

    $count = 0;
    $allEvents = true;
    foreach ($watcher as $event) {
        if (!($event instanceof FileSystemEvent)) {
            $allEvents = false;
        }
        $count++;
        if ($count >= 2) {
            $watcher->close();
        }
    }

    var_dump($allEvents);
    var_dump($count >= 2);
});

// Cleanup
delay(500);
@unlink($dir . '/early1.txt');
@unlink($dir . '/early2.txt');
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
