--TEST--
TaskGroup: cancel() - stops running tasks and clears queue
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup(1);

    $group->spawn(function() {
        suspend();
        suspend();
        return "should not finish";
    });

    $group->spawn(function() {
        return "queued - never started";
    });

    $group->cancel();

    var_dump($group->isSealed());
    var_dump($group->isFinished());
    echo "results: " . count($group->getResults()) . "\n";
    echo "done\n";
});
?>
--EXPECT--
bool(true)
bool(false)
results: 0
done
