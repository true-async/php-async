--TEST--
TaskGroup: race() - multiple race() calls return first settled
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "first"; });
    $group->spawn(function() { return "second"; });
    $group->seal();

    $r1 = $group->race()->await();
    echo "race 1: $r1\n";

    $r2 = $group->race()->await();
    echo "race 2: $r2\n";
});
?>
--EXPECT--
race 1: first
race 2: first
