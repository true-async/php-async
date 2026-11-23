--TEST--
Pool: a healthcheck that catches the shutdown's cancellation does not keep checking until the grace runs out
--FILE--
<?php

use Async\Pool;
use function Async\delay;

$start = hrtime(true);

register_shutdown_function(function () use ($start) {
    $ms = (hrtime(true) - $start) / 1e6;
    echo $ms < 1000 ? "process ends\n" : "process ends after {$ms} ms\n";
});

$checking = false;

$pool = new Pool(
    factory: fn () => 1,
    healthcheck: function ($r) use (&$checking) {
        $checking = true;
        echo "check\n";

        try {
            delay(50);
        } catch (\Throwable $e) {
            echo "check caught ", get_class($e), "\n";
        }

        return true;
    },
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

while (!$checking) {
    delay(1);
}

exit(0);
?>
--EXPECT--
check
check caught Async\AsyncCancellation
process ends
