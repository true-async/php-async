--TEST--
TaskGroup: count() - tracks total number of tasks
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    echo "count: " . $group->count() . "\n";

    $group->spawn(function() { return 1; });
    echo "count: " . $group->count() . "\n";

    $group->spawn(function() { return 2; });
    echo "count: " . $group->count() . "\n";

    $group->spawn(function() { return 3; });
    echo "count: " . $group->count() . "\n";

    echo "count(obj): " . count($group) . "\n";
});
?>
--EXPECT--
count: 0
count: 1
count: 2
count: 3
count(obj): 3
