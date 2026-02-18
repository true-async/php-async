--TEST--
TaskGroup: isFinished() and isSealed() state transitions
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup();

    echo "initial: finished=" . var_export($group->isFinished(), true)
       . " sealed=" . var_export($group->isSealed(), true) . "\n";

    $group->spawn(function() {
        suspend();
        return 1;
    });

    echo "after spawn: finished=" . var_export($group->isFinished(), true)
       . " sealed=" . var_export($group->isSealed(), true) . "\n";

    $group->seal();

    echo "after close: finished=" . var_export($group->isFinished(), true)
       . " sealed=" . var_export($group->isSealed(), true) . "\n";

    $group->all()->await();

    echo "after all: finished=" . var_export($group->isFinished(), true)
       . " sealed=" . var_export($group->isSealed(), true) . "\n";
});
?>
--EXPECT--
initial: finished=true sealed=false
after spawn: finished=false sealed=false
after close: finished=false sealed=true
after all: finished=true sealed=true
