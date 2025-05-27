--TEST--
Future: spawn() - multiple coroutines execution order
--FILE--
<?php

use function Async\spawn;

echo "start\n";

spawn(function() {
    echo "coroutine 1\n";
});

spawn(function() {
    echo "coroutine 2\n";
});

spawn(function() {
    echo "coroutine 3\n";
});

echo "end\n";
?>
--EXPECT--
start
end
coroutine 1
coroutine 2
coroutine 3