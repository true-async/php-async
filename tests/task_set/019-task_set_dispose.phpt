--TEST--
TaskSet: dispose() - cancels scope coroutines
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
        return "should be cancelled";
    });

    $set->dispose();

    echo "disposed\n";
    var_dump($set->isFinished());
});
?>
--EXPECT--
disposed
bool(false)
