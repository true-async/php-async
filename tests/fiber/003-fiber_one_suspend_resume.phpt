--TEST--
One suspend/resume cycle
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: One suspend/resume cycle\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        echo "Before suspend\n";
        $value = Fiber::suspend("suspended");
        echo "After resume, got: " . $value . "\n";
        return "done";
    });

    $suspended = $fiber->start();
    echo "Fiber suspended with: " . $suspended . "\n";

    $result = $fiber->resume("resume value");
    echo "Fiber returned: " . $result . "\n";

    return "complete";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: One suspend/resume cycle
Before suspend
Fiber suspended with: suspended
After resume, got: resume value
Fiber returned: done
Test completed
