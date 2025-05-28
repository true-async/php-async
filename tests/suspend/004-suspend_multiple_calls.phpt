--TEST--
Multiple suspend calls - repeated suspends in same coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    echo "Coroutine: Step 1\n";
    suspend();
    echo "Coroutine: Step 2\n";
    suspend();
    echo "Coroutine: Step 3\n";
    suspend();
    echo "Coroutine: Step 4\n";
});

spawn(function() {
    echo "Other coroutine: Task\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Coroutine: Step 1
Other coroutine: Task
Coroutine: Step 2
Coroutine: Step 3
Coroutine: Step 4