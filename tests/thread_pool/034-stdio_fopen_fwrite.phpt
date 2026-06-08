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

// Each task writes its OWN file. Concurrent appends ('a') to a single shared
// file are not atomic on Windows and can lose a write; per-task files keep the
// "worker I/O actually hits disk" coverage without the contention.
$base = tempnam(sys_get_temp_dir(), 'async_pool_io_');
@unlink($base);

spawn(function() use ($base) {
    $pool = new ThreadPool(2);
    $futures = [];
    for ($i = 0; $i < 4; $i++) {
        $futures[] = $pool->submit(static function () use ($i, $base): array {
            $f = fopen(sprintf('%s-%02d', $base, $i), 'w');
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
    $lines = [];
    for ($i = 0; $i < 4; $i++) {
        $lines[] = trim(file_get_contents(sprintf('%s-%02d', $base, $i)));
    }
    sort($lines);
    echo "lines=", count($lines), "\n";
    echo implode("\n", $lines), "\n";
});

register_shutdown_function(static function () use ($base) {
    for ($i = 0; $i < 4; $i++) { @unlink(sprintf('%s-%02d', $base, $i)); }
    @unlink($base);
});
?>
--EXPECT--
lines=4
line-00
line-01
line-02
line-03
