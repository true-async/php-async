--TEST--
TaskGroup: small error-branch surface (empty any, negative concurrency, duplicate integer key, spawn on completed)
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    // 1. any() on an empty group (L1472-1475)
    $empty = new TaskGroup();
    try {
        $empty->any();
        echo "no-throw-empty-any\n";
    } catch (\Async\AsyncException $e) {
        echo "empty-any: ", $e->getMessage(), "\n";
    }

    // 2. Negative concurrency argument to __construct (L1260-1262)
    try {
        new TaskGroup(-1);
        echo "no-throw-concurrency\n";
    } catch (\ValueError $e) {
        echo "negative concurrency: ", $e->getMessage(), "\n";
    }

    // 3. Duplicate integer key via spawnWithKey (L1322)
    $g3 = new TaskGroup();
    $g3->spawnWithKey(7, function() { return 'a'; });
    try {
        $g3->spawnWithKey(7, function() { return 'b'; });
        echo "no-throw-dup-int\n";
    } catch (\Async\AsyncException $e) {
        echo "dup int: ", $e->getMessage(), "\n";
    }
    $g3->cancel();
});

?>
--EXPECT--
empty-any: Cannot call any() on an empty TaskGroup
negative concurrency: Async\TaskGroup::__construct(): Argument #1 ($concurrency) must be between 0 and 4294967295
dup int: Duplicate key 7 in TaskGroup
