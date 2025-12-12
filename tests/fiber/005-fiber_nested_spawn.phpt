--TEST--
Nested spawn inside Fiber
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\suspend;

echo "Test: Nested spawn inside Fiber\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Fiber: spawning inner coroutine\n";

        $inner = spawn(function() {
            echo "Inner: started\n";
            suspend();
            echo "Inner: resumed\n";
            return "inner result";
        });

        $result = await($inner);
        echo "Fiber: inner returned: " . $result . "\n";

        return "fiber done";
    });

    $result = $fiber->start();
    echo "Fiber completed: " . $result . "\n";

    return "outer done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Nested spawn inside Fiber
Fiber: spawning inner coroutine
Inner: started
Inner: resumed
Fiber: inner returned: inner result
Fiber completed: fiber done
Test completed
