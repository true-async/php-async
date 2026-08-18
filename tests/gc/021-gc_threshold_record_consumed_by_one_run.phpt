--TEST--
GC 021: the full-buffer record is consumed by one run and does not leak into later collections
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

// One full buffer of collectible garbage: the coroutine collects it, adjusts
// once, and the record is spent. The explicit collections that follow find
// nothing and must not adjust from a record left behind.
await(spawn(function () {
    for ($i = 1; $i <= 5100; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        unset($a, $b);
    }
    delay(1);

    gc_collect_cycles();
    delay(1);
    gc_collect_cycles();
    delay(1);
}));

$status = gc_status();

echo "threshold: ", $status['threshold'], "\n";
echo "collected something: ", var_export($status['collected'] > 0, true), "\n";

?>
--EXPECT--
threshold: 10001
collected something: true
