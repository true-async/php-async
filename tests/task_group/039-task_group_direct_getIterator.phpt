--TEST--
TaskGroup: calling getIterator() directly throws "invalid state" (normal foreach goes through the get_iterator handler)
--FILE--
<?php

use Async\TaskGroup;

// Covers task_group.c METHOD(getIterator) at L1715-1721. Normal iteration
// via foreach() goes through the class's get_iterator handler; the PHP
// method body is only reached when code explicitly calls `getIterator()`.

$group = new TaskGroup();
try {
    $group->getIterator();
    echo "no-throw\n";
} catch (\Error $e) {
    echo "caught: ", $e->getMessage(), "\n";
}

?>
--EXPECT--
caught: An object of class Async\TaskGroup is not a traversable object in an invalid state
