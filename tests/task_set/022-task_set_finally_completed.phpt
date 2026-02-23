--TEST--
TaskSet: finally() - on already completed set calls immediately
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { return 1; });
    $set->seal();
    $set->joinAll()->await();

    echo "before finally\n";

    $set->finally(function(TaskSet $s) {
        echo "finally called synchronously\n";
    });

    echo "after finally\n";
});
?>
--EXPECT--
before finally
finally called synchronously
after finally
