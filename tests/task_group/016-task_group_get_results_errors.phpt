--TEST--
TaskGroup: getResults() and getErrors() return current state
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "ok"; }, "good");
    $group->spawn(function() { throw new \RuntimeException("bad"); }, "fail");

    $group->close();
    $group->all(ignoreErrors: true);

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
