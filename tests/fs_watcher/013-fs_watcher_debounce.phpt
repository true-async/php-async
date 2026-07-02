--TEST--
FileSystemWatcher - debounce collapses a burst into a single event
--FILE--
<?php

use Async\FileSystemWatcher;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_deb_' . getmypid();
@mkdir($dir, 0777, true);

// debounceMs = 200: a burst of writes within the quiet window is delivered once.
$watcher = new FileSystemWatcher($dir, false, true, 200);

spawn(function () use ($dir, $watcher) {
    delay(80);
    for ($i = 0; $i < 8; $i++) {
        file_put_contents("$dir/f$i.txt", 'x');
        delay(15);
    }
    delay(500);
    $watcher->close();
});

$iterations   = 0;
$filenameNull = false;

foreach ($watcher as $e) {
    if ($iterations === 0) {
        $filenameNull = ($e->filename === null);
    }
    $iterations++;
}

var_dump($iterations === 1);   // 8 writes collapsed into one delivery
var_dump($filenameNull);       // collapsed event carries filename = null

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
