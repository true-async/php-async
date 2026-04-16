--TEST--
spawn_thread() - WeakReference / WeakMap edge cases
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

class Node {
    public function __construct(public string $name = '') {}
}

$boot = function() {
    eval('class Node { public function __construct(public string $name = "") {} }');
};

spawn(function() use ($boot) {
    // === Case A: WR + referent returned from thread (result transfer path) ===
    $t = spawn_thread(function() {
        $obj = new Node('returned');
        return [$obj, WeakReference::create($obj)];
    }, bootloader: $boot);
    $r = await($t);
    echo "A is array: ", (is_array($r) ? "yes" : "no"), "\n";
    echo "A obj is Node: ", ($r[0] instanceof Node ? "yes" : "no"), "\n";
    echo "A wr resolves: ", ($r[1]->get() === $r[0] ? "yes" : "no"), "\n";
    echo "A name: ", $r[0]->name, "\n";

    // === Case B: source-side WR singleton — two captured WRs to the same
    // referent are actually the same WR instance, must remain so on the
    // receiving side ===
    $obj = new Node('singleton');
    $wr1 = WeakReference::create($obj);
    $wr2 = WeakReference::create($obj);
    echo "B source same wr: ", ($wr1 === $wr2 ? "yes" : "no"), "\n";
    $t = spawn_thread(function() use ($obj, $wr1, $wr2) {
        echo "B child wr1 === wr2: ", ($wr1 === $wr2 ? "yes" : "no"), "\n";
        echo "B child wr1->get() === obj: ", ($wr1->get() === $obj ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rB = await($t); echo "B result: ", $rB, "\n";

    // === Case C: WR returned from thread when referent is NOT also returned —
    // the receiving side has no strong holder, so WR must be dead ===
    $t = spawn_thread(function() {
        $local = new Node('lonely-return');
        return WeakReference::create($local);
        // $local goes out of scope here; only the WR is in retval
    }, bootloader: $boot);
    $wr = await($t);
    echo "C is wr: ", ($wr instanceof WeakReference ? "yes" : "no"), "\n";
    echo "C dead: ", ($wr->get() === null ? "yes" : "no"), "\n";

    // === Case D: WeakMap returned from thread with a key in the same array ===
    $t = spawn_thread(function() {
        $k = new Node('returned-key');
        $wm = new WeakMap();
        $wm[$k] = 'returned-value';
        return [$wm, $k];
    }, bootloader: $boot);
    $r = await($t);
    echo "D wm count: ", count($r[0]), "\n";
    echo "D wm[k]: ", $r[0][$r[1]], "\n";
    echo "D k name: ", $r[1]->name, "\n";

    // === Case E: WeakMap holding a WeakReference as a VALUE ===
    $obj = new Node('referent');
    $wr  = WeakReference::create($obj);
    $key = new Node('key');
    $wm  = new WeakMap();
    $wm[$key] = $wr;
    $t = spawn_thread(function() use ($wm, $key, $obj) {
        $stored_wr = $wm[$key];
        echo "E val is wr: ", ($stored_wr instanceof WeakReference ? "yes" : "no"), "\n";
        echo "E wr resolves to obj: ", ($stored_wr->get() === $obj ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rE = await($t); echo "E result: ", $rE, "\n";

    // === Case F: nested WeakMap (WM whose value is another WM) ===
    $outerKey = new Node('outer-key');
    $innerKey = new Node('inner-key');
    $inner = new WeakMap();
    $inner[$innerKey] = 'deep';
    $outer = new WeakMap();
    $outer[$outerKey] = $inner;
    $t = spawn_thread(function() use ($outer, $outerKey, $innerKey) {
        $innerSeen = $outer[$outerKey];
        echo "F inner is wm: ", ($innerSeen instanceof WeakMap ? "yes" : "no"), "\n";
        echo "F inner count: ", count($innerSeen), "\n";
        echo "F inner val: ", $innerSeen[$innerKey], "\n";
        return 'ok';
    }, bootloader: $boot);
    $rF = await($t); echo "F result: ", $rF, "\n";

    // === Case G: many WR transfers in one closure (sanity / leak smoke) ===
    $objects = [];
    $wrs = [];
    for ($i = 0; $i < 50; $i++) {
        $o = new Node("item-$i");
        $objects[] = $o;
        $wrs[] = WeakReference::create($o);
    }
    $t = spawn_thread(function() use ($objects, $wrs) {
        $matches = 0;
        for ($i = 0; $i < count($wrs); $i++) {
            if ($wrs[$i]->get() === $objects[$i]) {
                $matches++;
            }
        }
        echo "G matches: ", $matches, "\n";
        return 'ok';
    }, bootloader: $boot);
    $rG = await($t); echo "G result: ", $rG, "\n";
});
?>
--EXPECT--
A is array: yes
A obj is Node: yes
A wr resolves: yes
A name: returned
B source same wr: yes
B child wr1 === wr2: yes
B child wr1->get() === obj: yes
B result: ok
C is wr: yes
C dead: yes
D wm count: 1
D wm[k]: returned-value
D k name: returned-key
E val is wr: yes
E wr resolves to obj: yes
E result: ok
F inner is wm: yes
F inner count: 1
F inner val: deep
F result: ok
G matches: 50
G result: ok

