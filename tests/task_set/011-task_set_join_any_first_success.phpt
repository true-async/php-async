--TEST--
TaskSet: joinAny() - returns first successful result, ignoring errors
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() {
        throw new \RuntimeException("error1");
    });

    $set->spawn(function() {
        suspend();
        return "success";
    });

    $result = $set->joinAny()->await();
    echo "joinAny result: $result\n";
});
?>
--EXPECT--
joinAny result: success
