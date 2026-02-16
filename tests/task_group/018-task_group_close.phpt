--TEST--
TaskGroup: seal() - prevents new spawns, existing tasks continue
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\suspend;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() {
        suspend();
        return "completed";
    });

    $group->seal();

    var_dump($group->isSealed());

    try {
        $group->spawn(function() { return "new"; });
        echo "ERROR: no exception\n";
    } catch (\Throwable $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }

    $results = $group->all();
    echo "result: " . $results[0] . "\n";
});
?>
--EXPECT--
bool(true)
caught: Cannot spawn tasks on a sealed TaskGroup
result: completed
