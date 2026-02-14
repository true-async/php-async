--TEST--
Double cancel - parent coroutine and fiber's coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use Async\AsyncCancellation;

$parent = spawn(function() {
    $fiber = new Fiber(function() {
        Fiber::suspend();
        return "done";
    });

    $fiber->start();

    // Cancel fiber's coroutine
    $fiberCoro = $fiber->getCoroutine();
    $fiberCoro->cancel(new AsyncCancellation("fiber cancel"));
    echo "Fiber coroutine cancelled\n";

    // Try resume
    try {
        $fiber->resume();
    } catch (Throwable $e) {
        echo "Fiber caught: " . $e->getMessage() . "\n";
    }

    return "parent done";
});

// Also cancel parent
$parent->cancel(new AsyncCancellation("parent cancel"));
echo "Parent cancelled\n";

try {
    await($parent);
} catch (AsyncCancellation $e) {
    echo "Parent caught: " . $e->getMessage() . "\n";
}

echo "OK\n";
?>
--EXPECTF--
%a
OK
