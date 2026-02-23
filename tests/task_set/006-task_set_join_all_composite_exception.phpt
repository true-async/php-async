--TEST--
TaskSet: joinAll() - throws CompositeException when tasks fail
--FILE--
<?php

use Async\TaskSet;
use Async\CompositeException;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("good", function() { return "ok"; });
    $set->spawnWithKey("bad1", function() { throw new \RuntimeException("fail1"); });
    $set->spawnWithKey("bad2", function() { throw new \LogicException("fail2"); });

    $set->seal();

    try {
        $set->joinAll()->await();
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
