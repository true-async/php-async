--TEST--
Suspend with spawn integration - test suspend in combination with spawned coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

echo "Start\n";

spawn(function() {
    echo "Parent coroutine: Before spawn\n";
    
    spawn(function() {
        echo "Child coroutine: Before suspend\n";
        suspend();
        echo "Child coroutine: After suspend\n";
    });
    
    echo "Parent coroutine: After spawn\n";
    suspend();
    echo "Parent coroutine: After suspend\n";
    
    spawn(function() {
        echo "Another child coroutine\n";
    });
});

spawn(function() {
    echo "Independent coroutine\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Parent coroutine: Before spawn
Parent coroutine: After spawn
Independent coroutine
Child coroutine: Before suspend
Parent coroutine: After suspend
Child coroutine: After suspend
Another child coroutine