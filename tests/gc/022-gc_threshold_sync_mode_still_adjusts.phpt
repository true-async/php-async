--TEST--
GC 022: threshold adjustment in sync mode survives the async rework
--INI--
zend.enable_gc=1
--FILE--
<?php

// No async operation runs here, so every collection is inline and the caller
// adjusts from the real count - the pre-async contract, in both directions.

$live = [];

// Live cycles fill the buffer: the inline run frees nothing, one step up.
for ($i = 1; $i <= 5100; $i++) {
    $a = new stdClass();
    $b = new stdClass();
    $a->b = $b;
    $b->a = $a;
    $live[] = $a;
    unset($a, $b);
}

$raised = gc_status()['threshold'];

// The cycles become garbage and enough new garbage fills the raised buffer:
// the inline run collects everything, one step back down.
$live = null;
for ($i = 1; $i <= 7500; $i++) {
    $a = new stdClass();
    $b = new stdClass();
    $a->b = $b;
    $b->a = $a;
    unset($a, $b);
}

$lowered = gc_status()['threshold'];

echo "after raise: $raised\n";
echo "after lower: $lowered\n";

?>
--EXPECT--
after raise: 20001
after lower: 10001
