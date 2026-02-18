--TEST--
TaskGroup: race() - returns first completed result
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() {
        suspend();
        suspend();
        return "slow";
    });

    $group->spawn(function() {
        return "fast";
    });

    $result = $group->race()->await();
    echo "race result: $result\n";
});
?>
--EXPECT--
race result: fast
