--TEST--
Spawn 1000 coroutines return values
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "start\n";

$coroutines = [];

// create multiple coroutines that will return values after a delay
for ($i = 1; $i <= 1000; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        return "coroutine $i";
    });
}

echo "end\n";
?>
--EXPECT--
start
end