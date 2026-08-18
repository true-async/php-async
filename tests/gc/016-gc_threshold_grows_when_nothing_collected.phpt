--TEST--
GC 016: the threshold still grows in async mode when a collection frees nothing
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

$live = [];

$before = gc_status()['threshold'];

// The cycles stay referenced, so every collection frees nothing and the
// heuristic is supposed to raise the threshold - the same as in sync mode.
await(spawn(function () use (&$live) {
    for ($i = 1; $i <= 40000; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        $live[] = $a;
        unset($a, $b);

        if ($i % 10000 === 0) {
            delay(1);
        }
    }
}));

$status = gc_status();

echo "collections: ", $status['runs'] > 0 ? "ran\n" : "none\n";
echo "collected: ", $status['collected'], "\n";
echo "threshold grew: ", var_export($status['threshold'] > $before, true), "\n";

?>
--EXPECT--
collections: ran
collected: 0
threshold grew: true
