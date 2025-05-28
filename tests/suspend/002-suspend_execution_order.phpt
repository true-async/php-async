--TEST--
Suspend execution order - verify scheduler processes other coroutines during suspend
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    echo "Coroutine A: Before suspend\n";
    suspend();
    echo "Coroutine A: After suspend\n";
    suspend();
    echo "Coroutine A: After second suspend\n";
});

spawn(function() {
    echo "Coroutine B: Task 1\n";
    suspend();
    echo "Coroutine B: Task 2\n";
});

spawn(function() {
    echo "Coroutine C: Quick task\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Coroutine A: Before suspend
Coroutine B: Task 1
Coroutine C: Quick task
Coroutine A: After suspend
Coroutine B: Task 2
Coroutine A: After second suspend