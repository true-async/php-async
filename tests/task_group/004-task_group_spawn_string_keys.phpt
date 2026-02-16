--TEST--
TaskGroup: spawn() - with string keys
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "Alice"; }, "user1");
    $group->spawn(function() { return "Bob"; }, "user2");
    $group->spawn(function() { return "Charlie"; }, "user3");

    $group->seal();
    $results = $group->all();

    foreach ($results as $key => $value) {
        echo "$key => $value\n";
    }
});
?>
--EXPECT--
user1 => Alice
user2 => Bob
user3 => Charlie
