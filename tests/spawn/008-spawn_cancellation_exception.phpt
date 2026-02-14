--TEST--
Future: spawn() - AsyncCancellation handling (special case)
--FILE--
<?php

use function Async\spawn;
use Async\AsyncCancellation;

echo "start\n";

spawn(function() {
    echo "coroutine start\n";
    throw new AsyncCancellation("Cancelled");
    echo "coroutine end (should not print)\n";
});

echo "end\n";
?>
--EXPECT--
start
end
coroutine start