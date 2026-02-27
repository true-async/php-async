--TEST--
Multiple suspend/resume cycles
--FILE--
<?php

use function Async\spawn;
use function Async\await;

echo "Test: Multiple suspend/resume cycles\n";

$coroutine = spawn(function() {
    $fiber = new Fiber(function() {
        $v1 = Fiber::suspend("suspend-1");
        echo "Got: " . $v1 . "\n";

        $v2 = Fiber::suspend("suspend-2");
        echo "Got: " . $v2 . "\n";

        $v3 = Fiber::suspend("suspend-3");
        echo "Got: " . $v3 . "\n";

        return "final";
    });

    echo "R1: " . $fiber->start() . "\n";
    echo "R2: " . $fiber->resume("resume-1") . "\n";
    echo "R3: " . $fiber->resume("resume-2") . "\n";
    $fiber->resume("resume-3");
    echo "R4: " . $fiber->getReturn() . "\n";

    return "done";
});

await($coroutine);
echo "Test completed\n";
?>
--EXPECT--
Test: Multiple suspend/resume cycles
R1: suspend-1
Got: resume-1
R2: suspend-2
Got: resume-2
R3: suspend-3
Got: resume-3
R4: final
Test completed
