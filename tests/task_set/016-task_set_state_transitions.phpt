--TEST--
TaskSet: isFinished() and isSealed() state transitions
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet();

    echo "initial: finished=" . var_export($set->isFinished(), true)
       . " sealed=" . var_export($set->isSealed(), true) . "\n";

    $set->spawn(function() {
        suspend();
        return 1;
    });

    echo "after spawn: finished=" . var_export($set->isFinished(), true)
       . " sealed=" . var_export($set->isSealed(), true) . "\n";

    $set->seal();

    echo "after seal: finished=" . var_export($set->isFinished(), true)
       . " sealed=" . var_export($set->isSealed(), true) . "\n";

    $set->joinAll()->await();

    echo "after joinAll: finished=" . var_export($set->isFinished(), true)
       . " sealed=" . var_export($set->isSealed(), true) . "\n";
});
?>
--EXPECT--
initial: finished=true sealed=false
after spawn: finished=false sealed=false
after seal: finished=false sealed=true
after joinAll: finished=true sealed=true
