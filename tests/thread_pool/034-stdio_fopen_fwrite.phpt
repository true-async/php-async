--TEST--
ThreadPool: fopen/fwrite/fclose in worker actually writes to disk
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php
use Async\ThreadPool;
use function Async\spawn;
use function Async\await_all_or_fail;

$path = tempnam(sys_get_temp_dir(), 'async_pool_io_');

spawn(function() use ($path) {
    $pool = new ThreadPool(2);
    $futures = [];
    for ($i = 0; $i < 4; $i++) {
        $futures[] = $pool->submit(static function () use ($i, $path): array {
            $f = fopen($path, 'a');
            $is_res = is_resource($f);
            $written = fwrite($f, sprintf("line-%02d\n", $i));
            fclose($f);
            return ['is_resource' => $is_res, 'written' => $written];
        });
    }
    foreach (await_all_or_fail($futures) as $r) {
        if (!$r['is_resource'] || $r['written'] !== 8) {
            echo "FAIL: ", var_export($r, true), "\n";
            return;
        }
    }
    $pool->close();
    clearstatcache();
    $contents = file_get_contents($path);
    $lines = array_filter(explode("\n", $contents), 'strlen');
    sort($lines);
    echo "lines=", count($lines), "\n";
    echo implode("\n", $lines), "\n";
});

register_shutdown_function(static fn () => @unlink($path));
?>
--EXPECT--
lines=4
line-00
line-01
line-02
line-03
