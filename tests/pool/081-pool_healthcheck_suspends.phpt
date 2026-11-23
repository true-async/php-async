--TEST--
Pool: a healthcheck that suspends keeps a healthy resource and drops a dead one
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$pool = new Pool(
    factory: function () {
        static $n = 0;
        return ++$n;
    },
    destructor: function ($r) {
        echo "destroyed #$r\n";
    },
    healthcheck: function ($r) {
        delay(5);
        return $r !== 1;
    },
    min: 2,
    max: 2,
    healthcheckInterval: 20,
);

$other = spawn(function () {
    delay(100);
    return "unrelated coroutine finished";
});

echo await($other), "\n";
// #2 may be inside its check right now, so count, not idle.
echo "count=", $pool->count(), "\n";
?>
--EXPECT--
destroyed #1
unrelated coroutine finished
count=1
destroyed #2
