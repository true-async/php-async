--TEST--
Suspend with nested functions - test suspend called from nested function calls
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;

function nested_suspend() {
    echo "Nested function: Before suspend\n";
    suspend();
    echo "Nested function: After suspend\n";
}

function deeply_nested() {
    echo "Deeply nested: Start\n";
    nested_suspend();
    echo "Deeply nested: End\n";
}

echo "Start\n";

spawn(function() {
    echo "Coroutine: Before nested call\n";
    deeply_nested();
    echo "Coroutine: After nested call\n";
});

spawn(function() {
    echo "Other coroutine: Task\n";
});

echo "End\n";

?>
--EXPECT--
Start
End
Coroutine: Before nested call
Deeply nested: Start
Nested function: Before suspend
Other coroutine: Task
Nested function: After suspend
Deeply nested: End
Coroutine: After nested call