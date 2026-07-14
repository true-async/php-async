--TEST--
iterate() - concurrency limit is exact when callbacks park on a timer
--FILE--
<?php

use function Async\spawn;
use function Async\delay;
use function Async\iterate;

echo "start\n";

// suspend() reschedules the coroutine immediately, so the iteration loop drops back out of the
// callback before the freshly spawned workers ever enter it and the real peak stays invisible.
// delay() keeps every worker parked inside the callback at the same time.
spawn(function () {
    foreach ([1, 2, 3] as $concurrency) {
        $active = 0;
        $peak = 0;

        iterate(range(1, 8), function () use (&$active, &$peak) {
            $active++;

            if ($active > $peak) {
                $peak = $active;
            }

            delay(20);
            $active--;
        }, concurrency: $concurrency);

        echo "concurrency $concurrency: peak $peak\n";
    }

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
concurrency 1: peak 1
concurrency 2: peak 2
concurrency 3: peak 3
done
