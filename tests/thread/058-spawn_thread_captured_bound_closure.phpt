--TEST--
spawn_thread() - a captured $this-bound closure keeps its binding across transfer
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

class Greeter {
    public string $name = 'world';

    public function greet(): string {
        return "hello {$this->name}";
    }
}

/* The task closure is a free static closure transferred via the fcall
 * snapshot. Its captured $bound, however, is a $this-bound closure: it
 * travels as a bound variable and therefore goes through the generic
 * closure transfer (closure_transfer_obj), not the task snapshot path.
 *
 * Regression: closure_transfer_obj built the snapshot with only
 * fci_cache.function_handler set, dropping fci_cache.object — so the
 * worker rebuilt an unbound closure and invoking it dereferenced a
 * NULL $this. */
$boot = function () {
    eval('class Greeter { public string $name = "world"; public function greet(): string { return "hello {$this->name}"; } }');
};

spawn(function () use ($boot) {
    $g = new Greeter();
    $g->name = 'thread';

    $bound = $g->greet(...);   // Closure bound to $g

    $thread = spawn_thread(static function () use ($bound): string {
        return $bound();
    }, bootloader: $boot);

    echo await($thread), "\n";

    // The transferred binding is a deep copy — mutating it on the parent
    // side afterwards must not have affected the worker's result above.
    $g->name = 'mutated';
    echo "parent: ", $g->greet(), "\n";
});
?>
--EXPECT--
hello thread
parent: hello mutated
