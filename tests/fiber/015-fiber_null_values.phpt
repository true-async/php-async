--TEST--
Fiber with NULL values in suspend/resume
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: NULL values in suspend/resume\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        $v1 = Fiber::suspend();
        echo "Resume value is null: " . ($v1 === null ? "Y" : "N") . "\n";

        Fiber::suspend(null);

        return null;
    });

    $s1 = $fiber->start();
    echo "Suspend value is null: " . ($s1 === null ? "Y" : "N") . "\n";

    $s2 = $fiber->resume();
    echo "Suspend value is null: " . ($s2 === null ? "Y" : "N") . "\n";

    $r = $fiber->resume(null);
    echo "Return value is null: " . ($r === null ? "Y" : "N") . "\n";

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: NULL values in suspend/resume
Suspend value is null: Y
Resume value is null: Y
Suspend value is null: Y
Return value is null: Y
Test completed
