--TEST--
Pool: waiters are served in arrival order
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$pool = new Pool(factory: fn() => 'r', max: 1);

$order = [];

$workers = [];
for ($i = 0; $i < 6; $i++) {
    $workers[] = spawn(function() use ($pool, $i, &$order) {
        for ($k = 0; $k < 3; $k++) {
            $r = $pool->acquire();
            $order[] = $i;
            suspend();
            $pool->release($r);
            suspend();
        }
    });
}

foreach ($workers as $w) {
    await($w);
}

echo implode(' ', $order), "\n";
echo "Done\n";
?>
--EXPECT--
0 1 2 3 4 5 0 1 2 3 4 5 0 1 2 3 4 5
Done
