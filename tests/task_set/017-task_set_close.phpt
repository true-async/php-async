--TEST--
TaskSet: close() - prevents new tasks from being added
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return 1; });
    $set->close();

    try {
        $set->spawn(function() { return 2; });
        echo "ERROR: no exception\n";
    } catch (\Async\AsyncException $e) {
        echo "caught: spawn after close\n";
    }

    var_dump($set->isClosed());
});
?>
--EXPECT--
caught: spawn after close
bool(true)
