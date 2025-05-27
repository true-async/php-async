--TEST--
Future: spawn() - basic usage
--FILE--
<?php

use function Async\spawn;

echo "start\n";

spawn(function() {
    echo "coroutine\n";
});

echo "end\n";
?>
--EXPECT--
start
end
coroutine