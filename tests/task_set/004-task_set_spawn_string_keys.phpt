--TEST--
TaskSet: spawnWithKey() - string keys
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("x", function() { return 10; });
    $set->spawnWithKey("y", function() { return 20; });

    $set->seal();
    $results = $set->joinAll()->await();

    var_dump($results["x"]);
    var_dump($results["y"]);
});
?>
--EXPECT--
int(10)
int(20)
