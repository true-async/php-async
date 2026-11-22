--TEST--
Pool: a destructor that throws during the healthcheck is reported as a warning, and the check goes on
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$created = 0;
$destroyed = [];

$pool = new Pool(
    factory: function () use (&$created) {
        return ++$created;
    },
    destructor: function ($r) use (&$destroyed) {
        $destroyed[] = $r;
        throw new RuntimeException("close failed on #$r");
    },
    healthcheck: fn ($r) => false,
    min: 2,
    max: 2,
    healthcheckInterval: 20,
);

$other = spawn(function () {
    delay(60);
    return "unrelated coroutine finished";
});

delay(40);
sort($destroyed);
echo "destroyed: ", implode(",", $destroyed), "; count ", $pool->count(), "\n";
echo await($other), "\n";
$pool->close();
echo "done\n";
?>
--EXPECTF--
Warning: Pool: resource destructor threw RuntimeException: close failed on #1 in %s on line %d

Warning: Pool: resource destructor threw RuntimeException: close failed on #2 in %s on line %d
destroyed: 1,2; count 0
unrelated coroutine finished
done
