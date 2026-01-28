--TEST--
GC with include in suspended coroutine - symTable double DELREF
--FILE--
<?php

use function Async\spawn;
use function Async\suspend;
use function Async\await;

class Cycle {
    public $self;
}

try {
    $coroutine = spawn(function() {
        $parent1 = "value1";
        $parent2 = "value2";

        for ($i = 0; $i < 10000; $i++) {
            $obj = new Cycle();
            $obj->self = $obj;
        }

        // Suspend - coroutine is now in suspended state
        suspend();

        // Include inherits parent symTable
        // Bug: GC may add same variables twice -> double DELREF
        include __DIR__ . '/011-gc_include_symtable_double_delref_included.inc';

        echo "parent1: {$parent1}\n";
        echo "parent2: {$parent2}\n";
        echo "included: {$included}\n";

        return "done";
    });

    $result = await($coroutine);
    echo "result: {$result}\n";

} catch (Error $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "OK\n";
gc_collect_cycles();
?>
--EXPECTF--
parent1: value1
parent2: value2
included: included_value
result: done
OK
