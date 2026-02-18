--TEST--
TaskGroup: spawnWithKey() - with string keys
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawnWithKey("user1", function() { return "Alice"; });
    $group->spawnWithKey("user2", function() { return "Bob"; });
    $group->spawnWithKey("user3", function() { return "Charlie"; });

    $group->seal();
    $results = $group->all()->await();

    foreach ($results as $key => $value) {
        echo "$key => $value\n";
    }
});
?>
--EXPECT--
user1 => Alice
user2 => Bob
user3 => Charlie
