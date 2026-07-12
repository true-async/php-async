--TEST--
spawn_thread: cycle that runs through a closure's captured variable
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

/* Object -> closure -> same object.
 *
 * The existing cycle tests (059, 044) keep the whole cycle inside ONE closure:
 * $c->self = $c reached via $this. That is caught, because thread_copy_callable
 * builds one transfer context for that closure's bound vars and the xlat in it
 * sees the object twice.
 *
 * Here the cycle CROSSES a closure boundary: the object owns the closure, and
 * the closure captured the object. Copying the object reaches the closure, and
 * copying a closure starts a fresh transfer context — so the captured object is
 * not recognised as already-copied, and the copy starts over. */

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

class Holder {
    public $handler;
    public int $n = 7;
}

$boot = function() {
    eval('class Holder { public $handler; public int $n = 7; }');
};

spawn(function() use ($boot) {
    $h = new Holder();
    $h->handler = function() use ($h) {
        return $h;               /* hand the captured object back */
    };

    $t = spawn_thread(function() use ($h): string {
        $captured = ($h->handler)();

        /* The cycle must collapse to ONE object in the destination thread, not
         * two copies: that is the whole point of the already-copied table. */
        return 'same=' . var_export($captured === $h, true) . ' n=' . $h->n;
    }, bootloader: $boot);

    echo await($t), "\n";
    echo "DONE\n";
});
?>
--EXPECT--
same=true n=7
DONE
