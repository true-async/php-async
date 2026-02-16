--TEST--
TaskGroup: spawn() - auto-increment integer keys
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $group = new TaskGroup();

    $group->spawn(function() { return "a"; });
    $group->spawn(function() { return "b"; });
    $group->spawn(function() { return "c"; });

    $group->seal();
    $results = $group->all();

    var_dump($results);
});
?>
--EXPECT--
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  string(1) "b"
  [2]=>
  string(1) "c"
}
