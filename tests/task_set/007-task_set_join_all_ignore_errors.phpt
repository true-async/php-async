--TEST--
TaskSet: joinAll(ignoreErrors: true) - returns results without throwing
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("good", function() { return "ok"; });
    $set->spawnWithKey("bad", function() { throw new \RuntimeException("fail"); });

    $set->seal();
    $results = $set->joinAll(ignoreErrors: true)->await();

    echo "results count: " . count($results) . "\n";
    echo "result[good]: " . $results["good"] . "\n";
});
?>
--EXPECT--
results count: 1
result[good]: ok
