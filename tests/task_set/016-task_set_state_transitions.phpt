--TEST--
TaskSet: isFinished() and isClosed() state transitions
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet();

    echo "initial: finished=" . var_export($set->isFinished(), true)
       . " closed=" . var_export($set->isClosed(), true) . "\n";

    $set->spawn(function() {
        suspend();
        return 1;
    });

    echo "after spawn: finished=" . var_export($set->isFinished(), true)
       . " closed=" . var_export($set->isClosed(), true) . "\n";

    $set->close();

    echo "after close: finished=" . var_export($set->isFinished(), true)
       . " closed=" . var_export($set->isClosed(), true) . "\n";

    $set->joinAll()->await();

    echo "after joinAll: finished=" . var_export($set->isFinished(), true)
       . " closed=" . var_export($set->isClosed(), true) . "\n";
});
?>
--EXPECT--
initial: finished=true closed=false
after spawn: finished=false closed=false
after close: finished=false closed=true
after joinAll: finished=true closed=true
