--TEST--
Pool: exit() in a destructor during the healthcheck ends the script
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\delay;

$pool = new Pool(
    factory: function () {
        static $n = 0;
        return ++$n;
    },
    destructor: function ($r) {
        echo "destructor #$r\n";
        exit(3);
    },
    healthcheck: fn ($r) => false,
    min: 2,
    max: 2,
    healthcheckInterval: 20,
);

spawn(function () {
    delay(60);
    echo "unrelated coroutine still ran\n";
});

delay(100);
echo "main still ran\n";
// The second resource is destroyed when the pool closes at shutdown.
?>
--EXPECT--
destructor #1
destructor #2
