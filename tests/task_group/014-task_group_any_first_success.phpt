--TEST--
TaskGroup: any() - returns first successful result, ignoring errors
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() {
        throw new \RuntimeException("error1");
    });

    $group->spawn(function() {
        suspend();
        return "success";
    });

    $group->suppressErrors();
    $result = $group->any();
    echo "any result: $result\n";
});
?>
--EXPECT--
any result: success
