--TEST--
Pool: a real deadlock does not count or name the healthcheck coroutine
--FILE--
<?php

use Async\Pool;
use Async\Future;
use Async\FutureState;
use function Async\spawn;
use function Async\delay;

$pool = new Pool(
    factory: fn () => 1,
    healthcheck: fn ($r) => true,
    min: 1,
    max: 1,
    healthcheckInterval: 10,
);

$state = new FutureState();
$future = new Future($state);
spawn(fn () => $future->await());
delay(30);
?>
--EXPECTF--
%AFatal error: Uncaught Async\DeadlockError: Deadlock detected: no active coroutines, 1 coroutines in waiting%A
