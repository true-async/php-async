--TEST--
TaskGroup: all() - waits for all tasks and returns results
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return 10; }, "a");
    $group->spawn(function() { return 20; }, "b");
    $group->spawn(function() { return 30; }, "c");

    $group->seal();
    $results = $group->all();

    var_dump($results["a"]);
    var_dump($results["b"]);
    var_dump($results["c"]);
});
?>
--EXPECT--
int(10)
int(20)
int(30)
