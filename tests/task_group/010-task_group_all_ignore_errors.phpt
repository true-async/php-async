--TEST--
TaskGroup: all(ignoreErrors: true) - returns results despite errors
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "ok1"; }, "good");
    $group->spawn(function() { throw new \RuntimeException("fail"); }, "bad");
    $group->spawn(function() { return "ok2"; }, "good2");

    $group->close();
    $results = $group->all(ignoreErrors: true);

    var_dump(count($results));
    var_dump($results["good"]);
    var_dump($results["good2"]);

    $errors = $group->getErrors();
    var_dump(count($errors));
    echo $errors["bad"]->getMessage() . "\n";
});
?>
--EXPECT--
int(2)
string(3) "ok1"
string(3) "ok2"
int(1)
fail
