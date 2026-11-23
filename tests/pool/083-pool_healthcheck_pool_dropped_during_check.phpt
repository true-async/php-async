--TEST--
Pool: dropping the last reference while the healthcheck runs destroys the pool once the check returns
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
        return true;
    },
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

delay(20);
$pool = null;
echo "dropped\n";
delay(60);
echo "main ends\n";
?>
--EXPECT--
check #1
dropped
destroyed #1
main ends
