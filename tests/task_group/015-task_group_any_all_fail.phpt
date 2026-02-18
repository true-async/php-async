--TEST--
TaskGroup: any() - all tasks fail throws CompositeException
--FILE--
<?php

use Async\TaskGroup;
use Async\CompositeException;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { throw new \RuntimeException("fail1"); });
    $group->spawn(function() { throw new \LogicException("fail2"); });

    $group->seal();

    try {
        $group->any()->await();
        echo "ERROR: no exception\n";
    } catch (CompositeException $e) {
        echo "caught CompositeException\n";
        echo "error count: " . count($e->getExceptions()) . "\n";
    }
});
?>
--EXPECT--
caught CompositeException
error count: 2
