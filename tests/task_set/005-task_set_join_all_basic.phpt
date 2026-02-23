--TEST--
TaskSet: joinAll() - waits for all tasks and returns results
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("a", function() { return 10; });
    $set->spawnWithKey("b", function() { return 20; });
    $set->spawnWithKey("c", function() { return 30; });

    $set->seal();
    $results = $set->joinAll()->await();

    var_dump($results["a"]);
    var_dump($results["b"]);
    var_dump($results["c"]);
});
?>
--EXPECT--
int(10)
int(20)
int(30)
