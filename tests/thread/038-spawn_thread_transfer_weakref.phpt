--TEST--
spawn_thread() - WeakReference transfers across threads
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

class Bag {
    public function __construct(public string $tag = '') {}
}

$boot = function() {
    eval('class Bag { public function __construct(public string $tag = "") {} }');
};

spawn(function() use ($boot) {
    // Case A: $obj listed before $wr in bound vars — identity preserved.
    $obj = new Bag('first');
    $wr  = WeakReference::create($obj);
    $t = spawn_thread(function() use ($obj, $wr) {
        echo "A same: ", ($wr->get() === $obj ? "yes" : "no"), "\n";
        echo "A tag: ", $wr->get()->tag, "\n";
        return 'ok';
    }, bootloader: $boot);
    $rA = await($t); echo "A: ", $rA, "\n";

    // Case B: $wr listed before $obj — identity must still survive (this is
    // the load order that exposed a use-after-free before defer_release).
    $obj2 = new Bag('second');
    $wr2  = WeakReference::create($obj2);
    $t = spawn_thread(function() use ($wr2, $obj2) {
        echo "B same: ", ($wr2->get() === $obj2 ? "yes" : "no"), "\n";
        echo "B tag: ", $wr2->get()->tag, "\n";
        return 'ok';
    }, bootloader: $boot);
    $rB = await($t); echo "B: ", $rB, "\n";

    // Case C: only the WR is captured, the referent is not — child thread
    // has no strong holder, so the WR must be dead on the receiving side.
    $obj3 = new Bag('lonely');
    $wr3  = WeakReference::create($obj3);
    $t = spawn_thread(function() use ($wr3) {
        echo "C dead: ", ($wr3->get() === null ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rC = await($t); echo "C: ", $rC, "\n";

    // Case D: source-side referent already gone before transfer.
    $obj4 = new Bag('gone');
    $wr4  = WeakReference::create($obj4);
    unset($obj4);
    echo "D source dead: ", ($wr4->get() === null ? "yes" : "no"), "\n";
    $t = spawn_thread(function() use ($wr4) {
        echo "D dead on load: ", ($wr4->get() === null ? "yes" : "no"), "\n";
        return 'ok';
    }, bootloader: $boot);
    $rD = await($t); echo "D: ", $rD, "\n";
});
?>
--EXPECT--
A same: yes
A tag: first
A: ok
B same: yes
B tag: second
B: ok
C dead: yes
C: ok
D source dead: yes
D dead on load: yes
D: ok
