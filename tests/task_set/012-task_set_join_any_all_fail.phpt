--TEST--
TaskSet: joinAny() - throws CompositeException when all tasks fail
--FILE--
<?php

use Async\TaskSet;
use Async\CompositeException;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawn(function() { throw new \RuntimeException("err1"); });
    $set->spawn(function() { throw new \LogicException("err2"); });

    $set->seal();

    try {
        $set->joinAny()->await();
        echo "ERROR: no exception\n";
    } catch (CompositeException $e) {
        echo "caught CompositeException\n";
        echo "count: " . count($e->getExceptions()) . "\n";
    }
});
?>
--EXPECT--
caught CompositeException
count: 2
