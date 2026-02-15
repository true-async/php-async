--TEST--
TaskGroup: all() - empty group returns empty array immediately
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();
    $group->close();
    $results = $group->all();

    var_dump($results);
});
?>
--EXPECT--
array(0) {
}
