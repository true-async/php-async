--TEST--
Pool: release() does not free the caller's own reference to the resource
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$destroyed = 0;

$pool = new Pool(
    factory: fn() => new stdClass(),
    destructor: function($resource) use (&$destroyed) { $destroyed++; },
    beforeRelease: fn($r) => false,   // every release destroys the resource
    max: 1
);

$c = spawn(function() use ($pool, &$destroyed) {
    $r = $pool->acquire();
    $r->tag = 'mine';

    $pool->release($r);
    echo "Destroyed: $destroyed\n";

    // The pool destroyed its resource; the caller's variable is still valid.
    echo "Tag: " . $r->tag . "\n";
});

await($c);

echo "Idle: " . $pool->idleCount() . "\n";
echo "Done\n";
?>
--EXPECT--
Destroyed: 1
Tag: mine
Idle: 0
Done
