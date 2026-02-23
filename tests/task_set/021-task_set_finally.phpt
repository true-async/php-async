--TEST--
TaskSet: finally() - called when set is sealed and all tasks complete
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->finally(function(TaskSet $s) {
        echo "finally called\n";
        echo "count: " . $s->count() . "\n";
    });

    $set->spawn(function() { return "a"; });
    $set->spawn(function() { return "b"; });

    $set->seal();
    $set->joinAll()->await();

    echo "after joinAll\n";
});
?>
--EXPECTF--
after joinAll
finally called
count: 0
