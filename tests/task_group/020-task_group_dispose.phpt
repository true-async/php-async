--TEST--
TaskGroup: dispose() - cancels scope coroutines
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
        return "should be cancelled";
    });

    $group->dispose();
    $group->suppressErrors();

    echo "disposed\n";
    var_dump($group->isFinished());
});
?>
--EXPECT--
disposed
bool(true)
