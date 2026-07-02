--TEST--
FileSystemWatcher - recursive watch re-watches a subdirectory removed and recreated
--FILE--
<?php

use Async\FileSystemWatcher;
use function Async\spawn;
use function Async\delay;

$root = sys_get_temp_dir() . '/async_fsw_del_' . getmypid();
$deep = $root . '/sub/deep';
@mkdir($deep, 0777, true);

$watcher = new FileSystemWatcher($root, recursive: true);

spawn(function () use ($root, $deep, $watcher) {
    delay(100);
    file_put_contents($deep . '/a.txt', '1');       // change in the original nested dir
    delay(200);

    @unlink($deep . '/a.txt');
    @rmdir($deep);                                   // remove the watched subdir
    delay(250);

    @mkdir($deep, 0777, true);                       // recreate the SAME path
    delay(300);                                      // let the new watch install
    file_put_contents($deep . '/b.txt', '2');        // change inside the recreated dir
    delay(300);
    $watcher->close();
});

$sawA = false;
$sawB = false;

foreach ($watcher as $ev) {
    $fn = (string) $ev->filename;
    if (str_contains($fn, 'a.txt')) { $sawA = true; }
    if (str_contains($fn, 'b.txt')) { $sawB = true; }
    if ($sawA && $sawB) {
        break;
    }
}

$watcher->close();

var_dump($sawA);   // original nested change seen
var_dump($sawB);   // recreated-path change seen -> stale watch dropped, path re-watched

@unlink($deep . '/b.txt');
@rmdir($deep);
@rmdir($root . '/sub');
@rmdir($root);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
