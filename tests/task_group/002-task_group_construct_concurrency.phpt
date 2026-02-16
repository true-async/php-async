--TEST--
TaskGroup: __construct() - with concurrency limit
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $group = new TaskGroup(2);

    $order = [];

    $group->spawn(function() use (&$order) {
        $order[] = "task1-start";
        return "r1";
    });

    $group->spawn(function() use (&$order) {
        $order[] = "task2-start";
        return "r2";
    });

    $group->spawn(function() use (&$order) {
        $order[] = "task3-start";
        return "r3";
    });

    $group->seal();
    $results = $group->all();

    echo "count: " . count($results) . "\n";
    echo "done\n";
});
?>
--EXPECT--
count: 3
done
