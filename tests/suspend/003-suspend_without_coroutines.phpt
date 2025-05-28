--TEST--
Suspend without coroutines - test optimization path when no coroutines exist
--FILE--
<?php

use function Async\suspend;

echo "Before suspend call\n";

// This should use the optimization path in suspend() function
// where it returns early when no coroutines or microtasks exist
suspend();

echo "After suspend call\n";

?>
--EXPECT--
Before suspend call
After suspend call