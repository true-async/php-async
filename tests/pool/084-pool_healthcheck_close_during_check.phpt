--TEST--
Pool: close() while the healthcheck runs destroys the checked resource once
--FILE--
<?php

use Async\Pool;
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
        echo "check #$r\n";
        delay(30);
        echo "check #$r returns\n";
        return true;
    },
    min: 2,
    max: 2,
    healthcheckInterval: 10,
);

delay(20);
$pool->close();
echo "closed\n";
delay(60);
echo "count=", $pool->count(), "\n";
?>
--EXPECT--
check #1
destroyed #2
closed
destroyed #1
count=0
