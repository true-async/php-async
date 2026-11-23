--TEST--
Pool: a fatal error while the healthcheck is suspended in its hook leaves no timer behind
--FILE--
<?php

use Async\Pool;
use function Async\delay;

$checking = false;

$pool = new Pool(
    factory: fn () => 1,
    healthcheck: function ($r) use (&$checking) {
        $checking = true;
        delay(50);
        return true;
    },
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

while (!$checking) {
    delay(1);
}

eval('function strlen() {}');
?>
--EXPECTF--
Fatal error: Cannot redeclare function strlen() in %s : eval()'d code on line 1
