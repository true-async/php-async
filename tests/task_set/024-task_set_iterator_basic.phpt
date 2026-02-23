--TEST--
TaskSet: foreach iteration yields results as [result, error]
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("a", function() { return "first"; });
    $set->spawnWithKey("b", function() { return "second"; });
    $set->spawnWithKey("c", function() { return "third"; });

    $set->seal();

    foreach ($set as $key => $pair) {
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
