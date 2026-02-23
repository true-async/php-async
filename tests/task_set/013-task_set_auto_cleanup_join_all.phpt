--TEST--
TaskSet: auto-cleanup - count drops to 0 after joinAll()
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return "a"; });
    $set->spawn(function() { return "b"; });
    $set->spawn(function() { return "c"; });

    echo "before joinAll: count=" . $set->count() . "\n";

    $set->seal();
    $results = $set->joinAll()->await();

    echo "after joinAll: count=" . $set->count() . "\n";
    echo "results: " . count($results) . "\n";
});
?>
--EXPECT--
before joinAll: count=3
after joinAll: count=0
results: 3
