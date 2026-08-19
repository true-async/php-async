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
        // The last iteration lands on this branch too, which is what leaves
        // nothing uncollected by the time gc_status() below is read.
        if ($i % 10000 === 0) {
            delay(1);
        }
    }
}

$before = gc_status()['threshold'];

// Four full root buffers, each one collected by the GC coroutine.
await(spawn(fn() => make_cycles(40000)));

$status = gc_status();

// What was collected is asserted too: a threshold that never moves also
// describes an engine whose deferred collection never happens at all, and a
// collected count proves the collection ran. How many runs it took is not
// asserted — that says how often the GC coroutine got scheduled, which the
// code under test does not decide. Measured on this very body: a delay every
// 10000 cycles gives four runs, one every 40000 gives one, and no delay at all
// gives one, while the collected count is 80000 in each case.
echo "threshold before: $before\n";
echo "threshold after: ", $status['threshold'], "\n";
echo "collected: ", $status['collected'], "\n";

?>
--EXPECT--
threshold before: 10001
threshold after: 10001
collected: 80000
