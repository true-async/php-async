--TEST--
TaskSet: count() - tracks number of tasks in set
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    echo "count: " . $set->count() . "\n";

    $set->spawn(function() { return 1; });
    echo "count: " . $set->count() . "\n";

    $set->spawn(function() { return 2; });
    echo "count: " . $set->count() . "\n";

    echo "count(obj): " . count($set) . "\n";
});
?>
--EXPECT--
count: 0
count: 1
count: 2
count(obj): 2
