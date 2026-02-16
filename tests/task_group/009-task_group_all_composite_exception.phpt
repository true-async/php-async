--TEST--
TaskGroup: all() - throws CompositeException when tasks fail
--FILE--
<?php

use Async\TaskGroup;
use Async\CompositeException;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "ok"; }, "good");
    $group->spawn(function() { throw new \RuntimeException("fail1"); }, "bad1");
    $group->spawn(function() { throw new \LogicException("fail2"); }, "bad2");

    $group->seal();

    try {
        $group->all();
        echo "ERROR: no exception\n";
    } catch (CompositeException $e) {
        echo "caught CompositeException\n";
        $errors = $e->getExceptions();
        echo "error count: " . count($errors) . "\n";
        foreach ($errors as $err) {
            echo get_class($err) . ": " . $err->getMessage() . "\n";
        }
    }
});
?>
--EXPECT--
caught CompositeException
error count: 2
RuntimeException: fail1
LogicException: fail2
