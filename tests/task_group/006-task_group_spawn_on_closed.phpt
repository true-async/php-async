--TEST--
TaskGroup: spawn() - on sealed group throws error
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();
    $group->seal();

    try {
        $group->spawn(function() { return 1; });
        echo "ERROR: no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: Cannot spawn tasks on a sealed TaskGroup
