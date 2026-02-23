--TEST--
TaskSet: spawn() - auto-increment keys
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return "a"; });
    $set->spawn(function() { return "b"; });
    $set->spawn(function() { return "c"; });

    $set->seal();
    $results = $set->joinAll()->await();

    var_dump($results[0]);
    var_dump($results[1]);
    var_dump($results[2]);
});
?>
--EXPECT--
string(1) "a"
string(1) "b"
string(1) "c"
