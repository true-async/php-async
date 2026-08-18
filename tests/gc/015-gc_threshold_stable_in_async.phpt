--TEST--
GC 015: root buffer threshold stays at the default value in async mode
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

function make_cycles(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        unset($a, $b);

        // Give the GC coroutine a chance to run between full root buffers.
        if ($i % 10000 === 0) {
            delay(1);
        }
    }
}

$before = gc_status()['threshold'];

// Four full root buffers, each one collected by the GC coroutine.
await(spawn(fn() => make_cycles(40000)));

$status = gc_status();

// The counts are asserted too: a threshold that never moves also describes an
// engine whose deferred collection never happens at all.
echo "threshold before: $before\n";
echo "threshold after: ", $status['threshold'], "\n";
echo "runs: ", $status['runs'], "\n";
echo "collected: ", $status['collected'], "\n";

?>
--EXPECT--
threshold before: 10001
threshold after: 10001
runs: 4
collected: 80000
