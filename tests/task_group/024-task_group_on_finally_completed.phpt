--TEST--
TaskGroup: finally() - on already completed group calls immediately
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return 1; });
    $group->seal();
    $group->all();

    echo "before finally\n";

    $group->finally(function(TaskGroup $g) {
        echo "finally called synchronously\n";
    });

    echo "after finally\n";
});
?>
--EXPECT--
before finally
finally called synchronously
after finally
