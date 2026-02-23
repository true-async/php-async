--TEST--
TaskSet: cancel() - stops running tasks
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet(1);

    $set->spawn(function() {
        suspend();
        suspend();
        return "should not finish";
    });

    $set->spawn(function() {
        return "queued - never started";
    });

    $set->cancel();

    var_dump($set->isSealed());
    var_dump($set->isFinished());
    echo "done\n";
});
?>
--EXPECT--
bool(true)
bool(false)
done
