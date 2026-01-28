--TEST--
Get fiber's coroutine after fiber termination
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c = spawn(function() {
    $fiber = new Fiber(function() {
        return "done";
    });

    $result = $fiber->start();
    echo "Fiber completed: {$result}\n";

    // Get coroutine after fiber is terminated
    $coro = $fiber->getCoroutine();

    if ($coro !== null) {
        echo "Has coroutine: yes\n";
        echo "Is completed: " . ($coro->isCompleted() ? "yes" : "no") . "\n";
    } else {
        echo "Has coroutine: no\n";
    }

    return "ok";
});

await($c);
echo "OK\n";
?>
--EXPECTF--
Fiber completed: done
Has coroutine: yes
Is completed: yes
OK
