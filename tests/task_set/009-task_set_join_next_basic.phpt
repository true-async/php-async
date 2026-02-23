--TEST--
TaskSet: joinNext() - returns first completed result
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() {
        suspend();
        suspend();
        return "slow";
    });

    $set->spawn(function() {
        return "fast";
    });

    $result = $set->joinNext()->await();
    echo "joinNext result: $result\n";
});
?>
--EXPECT--
joinNext result: fast
