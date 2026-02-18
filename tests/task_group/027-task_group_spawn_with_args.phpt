--TEST--
TaskGroup: spawn() and spawnWithKey() with callable arguments
--FILE--
<?php

use Async\TaskGroup;
use function Async\spawn;

function compute(int $a, int $b): int {
    return $a + $b;
}

spawn(function() {
    $group = new TaskGroup();

    // spawn with variadic args (auto-increment keys)
    $group->spawn('compute', 1, 2);
    $group->spawn('compute', 10, 20);

    // spawnWithKey with variadic args
    $group->spawnWithKey("sum", 'compute', 100, 200);

    $group->seal();
    $results = $group->all()->await();

    var_dump($results[0]);
    var_dump($results[1]);
    var_dump($results["sum"]);
});
?>
--EXPECT--
int(3)
int(30)
int(300)
