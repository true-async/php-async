--TEST--
TaskSet: joinNext() - propagates error from first settled task
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() {
        suspend();
        return "slow";
    });

    $set->spawn(function() {
        throw new \RuntimeException("fast error");
    });

    try {
        $set->joinNext()->await();
        echo "ERROR: no exception\n";
    } catch (\RuntimeException $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: fast error
