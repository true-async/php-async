--TEST--
TaskSet: seal() - prevents new tasks from being added
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return 1; });
    $set->seal();

    try {
        $set->spawn(function() { return 2; });
        echo "ERROR: no exception\n";
    } catch (\Async\AsyncException $e) {
        echo "caught: spawn after seal\n";
    }

    var_dump($set->isSealed());
});
?>
--EXPECT--
caught: spawn after seal
bool(true)
