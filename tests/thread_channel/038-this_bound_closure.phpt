--TEST--
ThreadChannel: $this-bound closure transferred to a worker thread
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\ThreadChannel;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

class C {
    public int $n = 21;

    public function makeClosure(): Closure {
        return function() {
            return $this->n + 100;
        };
    }
}

$boot = function() {
    eval('class C { public int $n = 21; }');
};

spawn(function() use ($boot) {
    $ch = new ThreadChannel(1);

    $t = spawn_thread(function() use ($ch) {
        $cl = $ch->recv();
        echo "worker got: ", $cl(), "\n";
    }, bootloader: $boot);

    $c = new C();
    $ch->send($c->makeClosure());
    await($t);
});
?>
--EXPECT--
worker got: 121
