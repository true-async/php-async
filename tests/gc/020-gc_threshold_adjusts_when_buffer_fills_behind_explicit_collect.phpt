--TEST--
GC 020: a buffer filling behind an explicitly requested collection still adjusts the threshold
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

$live = [];

// The explicit request spawns the GC coroutine; the buffer then fills while
// that coroutine is still pending. The run frees nothing, so the full-buffer
// heuristic must still raise the threshold - the adjustment is not lost to
// the coroutine having been requested explicitly.
await(spawn(function () use (&$live) {
    for ($i = 1; $i <= 4000; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        $live[] = $a;
        unset($a, $b);
    }

    gc_collect_cycles();

    for ($i = 1; $i <= 1200; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        $live[] = $a;
        unset($a, $b);
    }

    delay(1);
}));

echo "threshold: ", gc_status()['threshold'], "\n";

?>
--EXPECT--
threshold: 20001
