--TEST--
TaskSet: auto-cleanup - entries removed during iteration
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("a", function() { return "first"; });
    $set->spawnWithKey("b", function() { return "second"; });

    $set->seal();

    foreach ($set as $key => [$result, $error]) {
        echo "$key => $result (count=" . $set->count() . ")\n";
    }

    echo "final count: " . $set->count() . "\n";
});
?>
--EXPECT--
a => first (count=1)
b => second (count=0)
final count: 0
