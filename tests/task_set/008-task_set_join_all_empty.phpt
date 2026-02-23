--TEST--
TaskSet: joinAll() - empty set returns empty array
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();
    $set->seal();

    $results = $set->joinAll()->await();

    var_dump(is_array($results));
    var_dump(count($results));
});
?>
--EXPECT--
bool(true)
int(0)
