--TEST--
TaskSet: __construct() - with concurrency limit
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $set = new TaskSet(2);
    $order = [];

    $set->spawn(function() use (&$order) {
        $order[] = "task1-start";
        suspend();
        $order[] = "task1-end";
        return "r1";
    });

    $set->spawn(function() use (&$order) {
        $order[] = "task2-start";
        suspend();
        $order[] = "task2-end";
        return "r2";
    });

    $set->spawn(function() use (&$order) {
        $order[] = "task3-start";
        return "r3";
    });

    $set->seal();
    $set->joinAll()->await();

    foreach ($order as $item) {
        echo "$item\n";
    }
});
?>
--EXPECT--
task1-start
task2-start
task1-end
task2-end
task3-start
