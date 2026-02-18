--TEST--
TaskGroup: race() - empty group throws error
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    try {
        $group->race();
        echo "ERROR: no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});
?>
--EXPECT--
caught: Cannot race on an empty TaskGroup
