--TEST--
TaskGroup: isFinished() and isClosed() state transitions
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup();

    echo "initial: finished=" . var_export($group->isFinished(), true)
       . " closed=" . var_export($group->isClosed(), true) . "\n";

    $group->spawn(function() {
        suspend();
        return 1;
    });

    echo "after spawn: finished=" . var_export($group->isFinished(), true)
       . " closed=" . var_export($group->isClosed(), true) . "\n";

    $group->close();

    echo "after close: finished=" . var_export($group->isFinished(), true)
       . " closed=" . var_export($group->isClosed(), true) . "\n";

    $group->all();

    echo "after all: finished=" . var_export($group->isFinished(), true)
       . " closed=" . var_export($group->isClosed(), true) . "\n";
});
?>
--EXPECT--
initial: finished=true closed=false
after spawn: finished=false closed=false
after close: finished=false closed=true
after all: finished=true closed=true
