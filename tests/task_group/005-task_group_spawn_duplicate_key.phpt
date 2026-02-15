--TEST--
TaskGroup: spawn() - duplicate key throws error
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return 1; }, "same");

    try {
        $group->spawn(function() { return 2; }, "same");
        echo "ERROR: no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: Duplicate key in TaskGroup
