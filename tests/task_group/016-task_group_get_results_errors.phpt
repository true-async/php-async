--TEST--
TaskGroup: getResults() and getErrors() return current state
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawnWithKey("good", function() { return "ok"; });
    $group->spawnWithKey("fail", function() { throw new \RuntimeException("bad"); });

    $group->seal();
    $group->all(ignoreErrors: true)->await();

    $results = $group->getResults();
    $errors = $group->getErrors();

    echo "results count: " . count($results) . "\n";
    echo "result[good]: " . $results["good"] . "\n";
    echo "errors count: " . count($errors) . "\n";
    echo "error[fail]: " . $errors["fail"]->getMessage() . "\n";
});
?>
--EXPECT--
results count: 1
result[good]: ok
errors count: 1
error[fail]: bad
