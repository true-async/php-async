--TEST--
TaskGroup: spawnWithKey() - duplicate key throws error
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawnWithKey("same", function() { return 1; });

    try {
        $group->spawnWithKey("same", function() { return 2; });
        echo "ERROR: no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: Duplicate key "same" in TaskGroup
