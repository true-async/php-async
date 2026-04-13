--TEST--
spawn_thread() - object identity preserved across bound variables
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

class Holder {
    public function __construct(public int $n = 0) {}
}

$boot = function() {
    eval('class Holder { public function __construct(public int $n = 0) {} }');
};

spawn(function() use ($boot) {
    // Case A: same object captured directly and nested inside an array —
    // must be the same instance on the receiving side, and mutation via
    // one reference must be visible via the other.
    $obj = new Holder(42);
    $wrapper = ['ref' => $obj];

    $t = spawn_thread(function() use ($obj, $wrapper) {
        echo "A identity: ", ($obj === $wrapper['ref'] ? "yes" : "no"), "\n";
        echo "A obj->n: ", $obj->n, "\n";
        $obj->n = 999;
        echo "A after mutate wrapper: ", $wrapper['ref']->n, "\n";
        return 'ok';
    }, bootloader: $boot);
    $rA = await($t);
    echo "A: ", $rA, "\n";

    // Case B: same object assigned to two separate captured variables.
    $h = new Holder(100);
    $h2 = $h;
    $t = spawn_thread(function() use ($h, $h2) {
        echo "B identity: ", ($h === $h2 ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rB = await($t);
    echo "B: ", $rB, "\n";

    // Case C: two distinct objects cross-referenced from two bound vars in
    // different structural positions — both must retain identity.
    $a = new Holder(1);
    $b = new Holder(2);
    $graph1 = ['root' => $a, 'other' => $b];
    $graph2 = ['root' => $b, 'other' => $a];

    $t = spawn_thread(function() use ($graph1, $graph2) {
        echo "C a same: ", ($graph1['root'] === $graph2['other'] ? "yes" : "no"), "\n";
        echo "C b same: ", ($graph1['other'] === $graph2['root'] ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rC = await($t);
    echo "C: ", $rC, "\n";
});
?>
--EXPECT--
A identity: yes
A obj->n: 42
A after mutate wrapper: 999
A: ok
B identity: yes
B: ok
C a same: yes
C b same: yes
C: ok
