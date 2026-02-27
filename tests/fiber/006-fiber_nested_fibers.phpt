--TEST--
Nested Fibers (Fiber inside Fiber)
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Nested Fibers\n";

$coroutine = spawn(function() {
    $outer = new Fiber(function() {
        echo "Outer fiber started\n";

        $inner = new Fiber(function() {
            echo "Inner fiber started\n";
            Fiber::suspend("inner suspend");
            echo "Inner fiber resumed\n";
            return "inner done";
        });

        $val = $inner->start();
        echo "Inner suspended: " . $val . "\n";

        $inner->resume();
        $result = $inner->getReturn();
        echo "Inner result: " . $result . "\n";

        return "outer done";
    });

    $outer->start();
    $result = $outer->getReturn();
    echo "Outer result: " . $result . "\n";

    return "complete";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Nested Fibers
Outer fiber started
Inner fiber started
Inner suspended: inner suspend
Inner fiber resumed
Inner result: inner done
Outer result: outer done
Test completed
