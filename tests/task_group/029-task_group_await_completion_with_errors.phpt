--TEST--
TaskGroup: awaitCompletion() - does not throw on task errors
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "ok"; });
    $group->spawn(function() { throw new \RuntimeException("fail"); });

    $group->seal();
    $group->awaitCompletion();

    echo "completed without exception\n";
    var_dump($group->isFinished());
    echo "results: " . count($group->getResults()) . "\n";
    echo "errors: " . count($group->getErrors()) . "\n";
});
?>
--EXPECT--
completed without exception
bool(true)
results: 1
errors: 1
