--TEST--
TaskGroup: onFinally() - called when group completes
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $group = new TaskGroup();

    $group->onFinally(function(TaskGroup $g) {
        echo "finally called\n";
        echo "count: " . $g->count() . "\n";
    });

    $group->spawn(function() { return "a"; });
    $group->spawn(function() { return "b"; });

    $group->seal();
    $group->all();

    echo "after all\n";
});
?>
--EXPECTF--
after all
finally called
count: 2
