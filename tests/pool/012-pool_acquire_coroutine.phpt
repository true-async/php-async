--TEST--
Pool: acquire - blocking acquire in coroutine
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        $id = ++$c;
        echo "Created: $id\n";
        return $id;
    },
    max: 2
);

$coroutine = spawn(function() use ($pool) {
    echo "Coroutine: acquiring\n";
    $r = $pool->acquire();
    echo "Coroutine: got $r\n";
    $pool->release($r);
    echo "Coroutine: released\n";
    return "done";
});

await($coroutine);

echo "Total: " . $pool->count() . "\n";
echo "Idle: " . $pool->idleCount() . "\n";

echo "Done\n";
?>
--EXPECT--
Coroutine: acquiring
Created: 1
Coroutine: got 1
Coroutine: released
Total: 1
Idle: 1
Done
