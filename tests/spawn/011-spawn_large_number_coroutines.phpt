--TEST--
Future: spawn() - large number of coroutines
--FILE--
<?php

use function Async\spawn;

echo "start\n";

$count = 100;
for ($i = 0; $i < $count; $i++) {
    spawn(function() use ($i) {
        // Only print some to avoid too much output
        if ($i % 20 == 0) {
            echo "coroutine $i\n";
        }
    });
}

echo "end\n";
?>
--EXPECT--
start
end
coroutine 0
coroutine 20
coroutine 40
coroutine 60
coroutine 80