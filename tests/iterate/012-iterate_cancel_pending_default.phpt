--TEST--
iterate() - cancelPending=true (default) cancels spawned coroutines after iteration
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\iterate;
use Async\AsyncCancellation;

echo "start\n";

spawn(function() {
    $spawned_completed = false;

    iterate([1, 2, 3], function($value, $key) use (&$spawned_completed) {
        // Spawn a child coroutine that takes longer than the iteration
        spawn(function() use (&$spawned_completed, $value) {
            try {
                echo "spawned $value started\n";
                suspend();
                suspend();
                suspend();
                $spawned_completed = true;
                echo "spawned $value completed\n";
            } catch (AsyncCancellation $e) {
                echo "spawned $value cancelled\n";
            }
        });
    });

    echo "spawned_completed: " . ($spawned_completed ? "true" : "false") . "\n";
    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
spawned 1 started
spawned 2 started
spawned 3 started
spawned 1 cancelled
spawned 2 cancelled
spawned 3 cancelled
spawned_completed: false
done
