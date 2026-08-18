--TEST--
Pool: releasing coroutine does not take its own slot back ahead of a parked waiter
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return "r" . ++$c;
    },
    max: 1
);

$log = [];

// Per-iteration acquire/release with no suspension point between release and
// the next acquire - the pattern the PDO pool binding produces.
$worker = function(string $name) use ($pool, &$log) {
    for ($i = 0; $i < 3; $i++) {
        $r = $pool->acquire();
        $log[] = $name . $i;
        suspend();
        $pool->release($r);
    }
};

$a = spawn($worker, 'A');
$b = spawn($worker, 'B');

await($a);
await($b);

echo implode(' ', $log), "\n";
echo "Created: " . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECT--
A0 B0 A1 B1 A2 B2
Created: 1
Done
