--TEST--
spawn_thread() - WeakMap transfers across threads
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

class Key {
    public function __construct(public string $name = '', public int $id = 0) {}
}

$boot = function() {
    eval('class Key { public function __construct(public string $name = "", public int $id = 0) {} }');
};

spawn(function() use ($boot) {
    // Case A: WeakMap with several entries; keys also captured separately so
    // we can look them up by reference on the receiving side.
    $k1 = new Key('alpha', 1);
    $k2 = new Key('beta', 2);
    $k3 = new Key('gamma', 3);
    $wm = new WeakMap();
    $wm[$k1] = 'value-1';
    $wm[$k2] = 42;
    $wm[$k3] = ['nested' => true];

    $t = spawn_thread(function() use ($wm, $k1, $k2, $k3) {
        echo "A count: ", count($wm), "\n";
        echo "A k1: ", $wm[$k1], "\n";
        echo "A k2: ", $wm[$k2], "\n";
        echo "A k3.nested: ", var_export($wm[$k3]['nested'], true), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rA = await($t); echo "A: ", $rA, "\n";

    // Case B: empty WeakMap.
    $empty = new WeakMap();
    $t = spawn_thread(function() use ($empty) {
        echo "B count: ", count($empty), "\n";
        echo "B is wm: ", ($empty instanceof WeakMap ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rB = await($t); echo "B: ", $rB, "\n";

    // Case C: WeakMap with a key that's NOT separately captured. The
    // receiving side has no strong holder, so by WeakMap semantics the
    // entry must vanish (matches single-thread behaviour: a weakly held
    // key with no other reference is collected immediately).
    $solo = new Key('solo', 777);
    $wm3 = new WeakMap();
    $wm3[$solo] = 'solo-value';

    $t = spawn_thread(function() use ($wm3) {
        echo "C count: ", count($wm3), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rC = await($t); echo "C: ", $rC, "\n";

    // Case D: same setup but the key is also captured — entry survives on
    // the receiving side because the closure holds the key strongly.
    $kd = new Key('persisted', 99);
    $wm4 = new WeakMap();
    $wm4[$kd] = 'kept';

    $t = spawn_thread(function() use ($wm4, $kd) {
        echo "D count: ", count($wm4), "\n";
        echo "D key id: ", $kd->id, "\n";
        echo "D val: ", $wm4[$kd], "\n";
        return 'ok';
    }, bootloader: $boot);
    $rD = await($t); echo "D: ", $rD, "\n";
});
?>
--EXPECT--
A count: 3
A k1: value-1
A k2: 42
A k3.nested: true
A: ok
B count: 0
B is wm: yes
B: ok
C count: 0
C: ok
D count: 1
D key id: 99
D val: kept
D: ok
