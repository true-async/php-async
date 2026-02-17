--TEST--
TaskGroup: all() - waits for all tasks and returns results
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawnWithKey("a", function() { return 10; });
    $group->spawnWithKey("b", function() { return 20; });
    $group->spawnWithKey("c", function() { return 30; });

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
