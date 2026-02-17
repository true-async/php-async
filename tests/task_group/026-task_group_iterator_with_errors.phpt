--TEST--
TaskGroup: foreach iteration delivers errors as [null, error]
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

spawn(function() {
    $group = new TaskGroup();

    $group->spawnWithKey("good", function() { return "ok"; });
    $group->spawnWithKey("bad", function() { throw new \RuntimeException("fail"); });

    $group->seal();

    foreach ($group as $key => $pair) {
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
