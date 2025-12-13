--TEST--
Nested fibers in separate coroutines (symmetric switching)
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    $outer = new Fiber(function() {
        echo "C1-O-start\n";

        $inner = new Fiber(function() {
            echo "C1-I-start\n";
            Fiber::suspend();
            echo "C1-I-resume\n";
        });

        $inner->start();
        echo "C1-O-middle\n";
        $inner->resume();
        echo "C1-O-end\n";
    });

    $outer->start();
});

$c2 = spawn(function() {
    $outer = new Fiber(function() {
        echo "C2-O-start\n";

        $inner = new Fiber(function() {
            echo "C2-I-start\n";
            Fiber::suspend();
            echo "C2-I-resume\n";
        });

        $inner->start();
        echo "C2-O-middle\n";
        $inner->resume();
        echo "C2-O-end\n";
    });

    $outer->start();
});

await($c1);
await($c2);

echo "OK\n";
?>
--EXPECT--
C1-O-start
C2-O-start
C1-I-start
C2-I-start
C1-O-middle
C2-O-middle
C1-I-resume
C2-I-resume
C1-O-end
C2-O-end
OK
