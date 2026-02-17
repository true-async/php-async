--TEST--
TaskGroup: any() - multiple any() calls return first success
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { throw new \RuntimeException("fail"); });
    $group->spawn(function() { return "success"; });
    $group->seal();

    $r1 = $group->any();
    echo "any 1: $r1\n";

    $r2 = $group->any();
    echo "any 2: $r2\n";
});
?>
--EXPECT--
any 1: success
any 2: success
