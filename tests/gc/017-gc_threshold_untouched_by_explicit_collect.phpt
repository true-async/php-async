--TEST--
GC 017: an explicit gc_collect_cycles() in async mode leaves the threshold alone
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

$before = gc_status()['threshold'];

// Twenty rounds of garbage, each one collected on request before the root
// buffer fills. The threshold heuristic belongs to the full-buffer trigger
// alone, so an explicit collection must not move it.
await(spawn(function () {
    for ($round = 1; $round <= 20; $round++) {
        for ($i = 1; $i <= 500; $i++) {
            $a = new stdClass();
            $b = new stdClass();
            $a->b = $b;
            $b->a = $a;
            unset($a, $b);
        }

        gc_collect_cycles();
        delay(1);
    }
}));

$status = gc_status();

echo "threshold before: $before\n";
echo "threshold after: ", $status['threshold'], "\n";
echo "collected: ", $status['collected'], "\n";

?>
--EXPECT--
threshold before: 10001
threshold after: 10001
collected: 20000
