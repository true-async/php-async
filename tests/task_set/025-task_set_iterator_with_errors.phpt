--TEST--
TaskSet: foreach iteration delivers errors as [null, error]
--FILE--
<?php

use Async\TaskSet;
use function Async\spawn;

spawn(function() {
    $set = new TaskSet();

    $set->spawnWithKey("good", function() { return "ok"; });
    $set->spawnWithKey("bad", function() { throw new \RuntimeException("fail"); });

    $set->seal();

    foreach ($set as $key => $pair) {
        [$result, $error] = $pair;
        if ($error !== null) {
            echo "$key => error: " . $error->getMessage() . "\n";
        } else {
            echo "$key => result: $result\n";
        }
    }

    echo "done\n";
});
?>
--EXPECTF--
good => result: ok
bad => error: fail
done
