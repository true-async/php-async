--TEST--
iterate() - cancelPending=false awaits spawned coroutines after iteration
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\iterate;

echo "start\n";

spawn(function() {
    $results = [];

    iterate([1, 2, 3], function($value, $key) use (&$results) {
        // Spawn a child coroutine that takes longer than the iteration
        spawn(function() use (&$results, $value) {
            suspend();
            suspend();
            $results[] = "spawned-$value";
        });
    }, cancelPending: false);

    sort($results);
    echo implode(',', $results) . "\n";
    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
spawned-1,spawned-2,spawned-3
done
