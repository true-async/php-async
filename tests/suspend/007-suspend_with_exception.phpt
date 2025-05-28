--TEST--
Suspend with exception handling - verify suspend doesn't break exception flow
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    try {
        echo "Coroutine: Before suspend\n";
        suspend();
        echo "Coroutine: After suspend\n";
        throw new Exception("Test exception");
    } catch (Exception $e) {
        echo "Coroutine: Caught exception: " . $e->getMessage() . "\n";
    }
});

spawn(function() {
    echo "Other coroutine: Task\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Coroutine: Before suspend
Other coroutine: Task
Coroutine: After suspend
Coroutine: Caught exception: Test exception