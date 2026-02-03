--TEST--
Pool: acquire blocks when no resources available, wakes on release
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    max: 1
);

$c1Got = null;
$c2Got = null;
$c2WasBlocked = false;

// First coroutine holds the only resource
$c1 = spawn(function() use ($pool, &$c1Got) {
    $r = $pool->acquire();
    $c1Got = $r;

    // Let C2 start waiting
    \Async\suspend();
    \Async\suspend();

    $pool->release($r);
});

// Second coroutine must wait for release
$c2 = spawn(function() use ($pool, &$c2Got, &$c2WasBlocked) {
    // At this point C1 holds the resource
    $c2WasBlocked = ($pool->idleCount() === 0);
    $r = $pool->acquire();
    $c2Got = $r;
    $pool->release($r);
});

await($c1);
await($c2);

echo "C1 got resource: " . ($c1Got !== null ? "yes" : "no") . "\n";
echo "C2 got resource: " . ($c2Got !== null ? "yes" : "no") . "\n";
echo "C2 had to wait: " . ($c2WasBlocked ? "yes" : "no") . "\n";
echo "Same resource reused: " . ($c1Got === $c2Got ? "yes" : "no") . "\n";
echo "Total resources: " . $pool->count() . "\n";
?>
--EXPECT--
C1 got resource: yes
C2 got resource: yes
C2 had to wait: yes
Same resource reused: yes
Total resources: 1
