--TEST--
TaskGroup: all() - multiple all() calls on sealed group
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "a"; });
    $group->spawn(function() { return "b"; });
    $group->seal();

    $results1 = $group->all()->await();
    echo "first all: " . count($results1) . "\n";

    $results2 = $group->all()->await();
    echo "second all: " . count($results2) . "\n";

    var_dump($results1[0]);
    var_dump($results1[1]);
});
?>
--EXPECT--
first all: 2
second all: 2
string(1) "a"
string(1) "b"
