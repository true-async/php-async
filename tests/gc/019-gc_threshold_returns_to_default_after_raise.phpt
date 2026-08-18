--TEST--
GC 019: a raised threshold comes back down when a run collects again
--INI--
zend.enable_gc=1
--FILE--
<?php

use function Async\await;
use function Async\delay;
use function Async\spawn;

$live = [];

// One full buffer of live cycles: the run frees nothing, one step up.
await(spawn(function () use (&$live) {
    for ($i = 1; $i <= 5100; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        $live[] = $a;
        unset($a, $b);
    }
    delay(1);
}));

echo "after raise: ", gc_status()['threshold'], "\n";

// The cycles become garbage, and enough new garbage fills the raised buffer.
// That run collects everything, so the threshold steps back to the default.
await(spawn(function () use (&$live) {
    $live = null;
    for ($i = 1; $i <= 7500; $i++) {
        $a = new stdClass();
        $b = new stdClass();
        $a->b = $b;
        $b->a = $a;
        unset($a, $b);
    }
    delay(1);
}));

$status = gc_status();

echo "after lower: ", $status['threshold'], "\n";
echo "collected something: ", var_export($status['collected'] > 0, true), "\n";

?>
--EXPECT--
after raise: 20001
after lower: 10001
collected something: true
