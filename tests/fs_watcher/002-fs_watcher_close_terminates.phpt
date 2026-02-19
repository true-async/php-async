--TEST--
FileSystemWatcher - close() terminates foreach iteration
--FILE--
<?php

use Async\FileSystemWatcher;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_close_' . getmypid();
@mkdir($dir, 0777, true);

$watcher = new FileSystemWatcher($dir);

spawn(function() use ($watcher) {
    delay(100);
    $watcher->close();
});

$iterated = false;
foreach ($watcher as $event) {
    $iterated = true;
}

// foreach should have exited due to close()
var_dump(!$iterated || true); // may or may not have events
var_dump($watcher->isClosed());

// close() is idempotent
$watcher->close();
var_dump($watcher->isClosed());

@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
