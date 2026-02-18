--TEST--
TaskGroup: foreach iteration yields results as [result, error]
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawnWithKey("a", function() { return "first"; });
    $group->spawnWithKey("b", function() { return "second"; });
    $group->spawnWithKey("c", function() { return "third"; });

    $group->seal();

    foreach ($group as $key => $pair) {
        [$result, $error] = $pair;
        echo "$key => result=$result error=" . ($error === null ? "null" : $error->getMessage()) . "\n";
    }

    echo "iteration done\n";
});
?>
--EXPECT--
a => result=first error=null
b => result=second error=null
c => result=third error=null
iteration done
