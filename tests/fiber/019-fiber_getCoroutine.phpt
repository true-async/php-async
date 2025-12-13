--TEST--
Fiber::getCoroutine() method
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// Test with coroutine
$c = spawn(function() {
    $f = new Fiber(function() {
        Fiber::suspend();
    });

    $f->start();

    $coro = $f->getCoroutine();
    echo "Has coroutine: " . ($coro !== null ? "yes" : "no") . "\n";
    echo "Coroutine ID: " . $coro->getId() . "\n";
    echo "Is started: " . ($coro->isStarted() ? "yes" : "no") . "\n";
    echo "Is suspended: " . ($coro->isSuspended() ? "yes" : "no") . "\n";

    $f->resume();
});

await($c);

// Test without coroutine (regular fiber)
$f = new Fiber(function() {
    Fiber::suspend();
});

$f->start();

$coro = $f->getCoroutine();
echo "Regular fiber coroutine: " . ($coro === null ? "null" : "not-null") . "\n";

$f->resume();

echo "OK\n";
?>
--EXPECT--
Has coroutine: yes
Coroutine ID: 2
Is started: yes
Is suspended: yes
Regular fiber coroutine: null
OK
