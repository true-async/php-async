--TEST--
TaskGroup: spawn() - on closed group throws error
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();
    $group->close();

    try {
        $group->spawn(function() { return 1; });
        echo "ERROR: no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: Cannot spawn tasks on a closed TaskGroup
