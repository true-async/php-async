--TEST--
Multiple fiber suspend/resume in different coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    $f = new Fiber(function() {
        echo "C1-1\n";
        Fiber::suspend();
        echo "C1-2\n";
        Fiber::suspend();
        echo "C1-3\n";
    });

    $f->start();
    $f->resume();
    $f->resume();
});

$c2 = spawn(function() {
    $f = new Fiber(function() {
        echo "C2-1\n";
        Fiber::suspend();
        echo "C2-2\n";
        Fiber::suspend();
        echo "C2-3\n";
    });

    $f->start();
    $f->resume();
    $f->resume();
});

await($c1);
await($c2);

echo "OK\n";
?>
--EXPECT--
C1-1
C2-1
C1-2
C2-2
C1-3
C2-3
OK
