--TEST--
Pool: a coroutine cancelled inside beforeAcquire is cancelled, not handed the next resource
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$pool = new Pool(
    factory: function () {
        static $n = 0;
        echo "created ", ++$n, "\n";
        return $n;
    },
    destructor: function ($r) { echo "destroyed $r\n"; },
    beforeAcquire: function ($r) {
        delay(1000);
        return true;
    },
    min: 1,
    max: 2,
);

$coroutine = spawn(function () use ($pool) {
    $r = $pool->acquire();
    echo "acquired $r\n";
});

spawn(function () use ($coroutine) {
    delay(10);
    $coroutine->cancel();
});

try {
    await($coroutine);
} catch (Async\AsyncCancellation $e) {
    echo get_class($e), "\n";
}

echo "count={$pool->count()} active={$pool->activeCount()}\n";
?>
--EXPECT--
created 1
destroyed 1
Async\AsyncCancellation
count=0 active=0
