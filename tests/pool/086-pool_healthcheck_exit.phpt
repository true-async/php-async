--TEST--
Pool: exit() in a healthcheck ends the script
--FILE--
<?php

use Async\Pool;
use function Async\delay;

register_shutdown_function(function () {
    echo "process ends\n";
});

$pool = new Pool(
    factory: fn () => 1,
    healthcheck: function ($r) {
        echo "check\n";
        exit(0);
    },
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

delay(50);
echo "not reached\n";
?>
--EXPECT--
check
process ends
