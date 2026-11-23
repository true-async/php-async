--TEST--
Pool: the script ends while a suspending healthcheck runs
--FILE--
<?php

use Async\Pool;
use function Async\delay;

$start = hrtime(true);

register_shutdown_function(function () use ($start) {
    $ms = (hrtime(true) - $start) / 1e6;
    echo $ms < 1000 ? "process ends\n" : "process ends after {$ms} ms\n";
});

$pool = new Pool(
    factory: fn () => 1,
    healthcheck: function ($r) {
        echo "check\n";
        delay(50);
        return true;
    },
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

delay(30);
echo "main ends\n";
?>
--EXPECT--
check
main ends
process ends
