--TEST--
GC 018: the threshold stays at the default in async mode with no coroutine running
--INI--
zend.enable_gc=1
--FILE--
<?php

// A single stream read is enough to switch the GC to the async path; from
// there on the collection is handed to the GC coroutine even though the code
// below never spawns one and never suspends.
file_get_contents(__FILE__);

$before = gc_status()['threshold'];

for ($i = 1; $i <= 40000; $i++) {
    $a = new stdClass();
    $b = new stdClass();
    $a->b = $b;
    $b->a = $a;
    unset($a, $b);
}

$status = gc_status();

echo "threshold before: $before\n";
echo "threshold after: ", $status['threshold'], "\n";

?>
--EXPECT--
threshold before: 10001
threshold after: 10001
