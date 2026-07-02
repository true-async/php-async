--TEST--
ThreadPool - submit() during a suspended reload() lands on the fresh cohort
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\delay;
use function Async\await;

$pool = new ThreadPool(1);

// Occupy the single worker so the rotation hangs in its drain phase.
$f1 = $pool->submit(function () {
    usleep(700000);
    return 1;
});

delay(150);

$reload = spawn(function () use ($pool) {
    $pool->reload();
    return 'reloaded';
});

delay(150);   // reload started: channel swapped, waiting for the old worker

$f2 = $pool->submit(fn() => 2);   // goes to the NEW channel

var_dump(await($f1) === 1);            // in-flight task on the old cohort
var_dump(await($f2) === 2);            // executed by the replacement
var_dump(await($reload) === 'reloaded');

$pool->close();

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
