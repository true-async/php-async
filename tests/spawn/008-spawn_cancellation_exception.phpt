--TEST--
Future: spawn() - CancellationError handling (special case)
--FILE--
<?php

use function Async\spawn;
use Async\CancellationError;

echo "start\n";

spawn(function() {
    echo "coroutine start\n";
    throw new CancellationError("Cancelled");
    echo "coroutine end (should not print)\n";
});

echo "end\n";
?>
--EXPECT--
start
end
coroutine start