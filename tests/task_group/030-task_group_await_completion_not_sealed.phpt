--TEST--
TaskGroup: awaitCompletion() - throws if group is not closed
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return 1; });

    try {
        $group->awaitCompletion();
        echo "should not reach here\n";
    } catch (\Async\AsyncException $e) {
        echo $e->getMessage() . "\n";
    }

    $group->close();
    echo "done\n";
});
?>
--EXPECT--
TaskGroup must be closed before calling awaitCompletion()
done
