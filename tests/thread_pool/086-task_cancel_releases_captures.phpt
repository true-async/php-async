--TEST--
ThreadPool: a cancelled task releases what it captured, without waiting for close()
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$probeClass = <<<'PHP'
<?php
final class Probe
{
    public function __construct(public readonly string $name) {}

    public function __destruct()
    {
        if (isset($GLOBALS['ON_WORKER'])) {
            echo "worker released {$this->name}\n";
        }
    }
}
PHP;

$file = sys_get_temp_dir() . '/tp_probe_' . getmypid() . '.php';
file_put_contents($file, $probeClass);
require $file;

spawn(function() use ($file) {
    $pool = new ThreadPool(
        workers: 1,
        bootloader: static function() use ($file) {
            require $file;
            $GLOBALS['ON_WORKER'] = true;
        },
        coroutine: true,
    );

    for ($i = 1; $i <= 2; $i++) {
        $probe = new Probe("probe-$i");

        $task = $pool->submit(static function() use ($probe) {
            delay(20000);
            return 'never';
        });

        delay(200);
        $task->cancel();

        try { await($task); } catch (Async\AsyncCancellation $e) {}

        unset($probe, $task);
        delay(200);
        echo "after cancellation $i\n";
    }

    $pool->close();
    delay(200);
    echo "after close\n";
});

register_shutdown_function(static fn() => @unlink($file));
?>
--EXPECT--
worker released probe-1
after cancellation 1
worker released probe-2
after cancellation 2
after close
