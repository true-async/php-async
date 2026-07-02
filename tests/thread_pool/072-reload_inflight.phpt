--TEST--
ThreadPool - reload() waits for an in-flight task; the task survives the rotation
--FILE--
<?php

use Async\ThreadPool;
use function Async\delay;
use function Async\await;

$pool = new ThreadPool(1);

$f = $pool->submit(function () {
    usleep(500000);
    return 'slow-done';
});

delay(100);   // the single worker is busy inside the task

$t0 = microtime(true);
$pool->reload();   // must suspend until the old worker finishes and exits
$elapsed = microtime(true) - $t0;

var_dump(await($f) === 'slow-done');   // in-flight task was not dropped
var_dump($elapsed >= 0.25);            // reload actually waited for the drain
var_dump(await($pool->submit(fn() => 42)) === 42);   // fresh worker serves

$pool->close();

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
