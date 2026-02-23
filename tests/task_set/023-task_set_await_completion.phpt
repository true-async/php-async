--TEST--
TaskSet: awaitCompletion() - waits for all tasks to settle
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return "a"; });
    $set->spawn(function() { return "b"; });

    $set->seal();
    $set->awaitCompletion();

    echo "completed\n";
    var_dump($set->isFinished());
});
?>
--EXPECT--
completed
bool(true)
