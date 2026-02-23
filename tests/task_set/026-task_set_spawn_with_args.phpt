--TEST--
TaskSet: spawn() - passes arguments to task callable
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function(int $a, int $b) {
        return $a + $b;
    }, 10, 20);

    $set->seal();
    $results = $set->joinAll()->await();
    var_dump($results[0]);
});
?>
--EXPECT--
int(30)
