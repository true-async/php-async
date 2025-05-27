--TEST--
Future: spawn() - CancellationException handling (special case)
--FILE--
<?php

use function Async\spawn;
use Async\CancellationException;

echo "start\n";

spawn(function() {
    echo "coroutine start\n";
    throw new CancellationException("Cancelled");
    echo "coroutine end (should not print)\n";
});

echo "end\n";
?>
--EXPECT--
start
end
coroutine start