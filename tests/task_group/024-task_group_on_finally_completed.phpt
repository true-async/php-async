--TEST--
TaskGroup: onFinally() - on already completed group calls immediately
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return 1; });
    $group->close();
    $group->all();

    echo "before onFinally\n";

    $group->onFinally(function(TaskGroup $g) {
        echo "finally called synchronously\n";
    });

    echo "after onFinally\n";
});
?>
--EXPECT--
before onFinally
finally called synchronously
after onFinally
