--TEST--
Pool: close() while the healthcheck runs destroys the checked resource once
--FILE--
<?php

use Async\Pool;
use function Async\delay;

$checking = false;

$pool = new Pool(
    factory: function () {
        static $n = 0;
        return ++$n;
    },
    destructor: function ($r) {
        echo "destroyed #$r\n";
    },
    healthcheck: function ($r) use (&$checking) {
        $checking = true;
        echo "check #$r\n";
        delay(30);
        echo "check #$r returns\n";
        return true;
    },
    min: 2,
    max: 2,
    healthcheckInterval: 10,
);

// Wait until the healthcheck is inside its hook.
while (!$checking) {
    delay(1);
}

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
