--TEST--
TaskSet: auto-cleanup - entry removed after joinNext()
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return "first"; });
    $set->spawn(function() { return "second"; });

    echo "before: count=" . $set->count() . "\n";

    $r1 = $set->joinNext()->await();
    echo "after first joinNext: count=" . $set->count() . "\n";

    $r2 = $set->joinNext()->await();
    echo "after second joinNext: count=" . $set->count() . "\n";

    echo "results: $r1, $r2\n";
});
?>
--EXPECT--
before: count=2
after first joinNext: count=1
after second joinNext: count=0
results: first, second
