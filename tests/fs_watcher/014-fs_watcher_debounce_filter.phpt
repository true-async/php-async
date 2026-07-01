--TEST--
FileSystemWatcher - debounce extension filter ignores non-matching files
--FILE--
<?php

use Async\FileSystemWatcher;
use function Async\spawn;
use function Async\delay;

$dir = sys_get_temp_dir() . '/async_fsw_flt_' . getmypid();
@mkdir($dir, 0777, true);

// Only *.php changes count; everything else is ignored in debounce mode.
$watcher = new FileSystemWatcher($dir, false, true, 120, 0, ['php']);

spawn(function () use ($dir, $watcher) {
    delay(80);
    file_put_contents("$dir/ignore.log", 'x');   // filtered out
    file_put_contents("$dir/also.tmp", 'y');      // filtered out
    delay(450);                                    // long enough for a flush, if any
    $watcher->close();
});

$iterations = 0;

foreach ($watcher as $e) {
    $iterations++;
}

var_dump($iterations === 0);   // no *.php change occurred -> nothing delivered

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

echo "done\n";
?>
--EXPECT--
bool(true)
done
