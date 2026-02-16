--TEST--
TaskGroup: concurrency limit queues excess tasks
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup(1);
    $order = [];

    $group->spawn(function() use (&$order) {
        $order[] = "task1-start";
        suspend();
        $order[] = "task1-end";
        return "r1";
    });

    $group->spawn(function() use (&$order) {
        $order[] = "task2-start";
        suspend();
        $order[] = "task2-end";
        return "r2";
    });

    $group->spawn(function() use (&$order) {
        $order[] = "task3-start";
        return "r3";
    });

    $group->seal();
    $group->all();

    foreach ($order as $item) {
        echo "$item\n";
    }
});
?>
--EXPECT--
task1-start
task1-end
task2-start
task2-end
task3-start
