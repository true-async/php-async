--TEST--
ThreadChannel: object whose property table was materialized survives a real cross-thread round-trip
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

class Box {
    public int $n = 0;
    public string $s = "";
}

$boot = function () {
    eval('class Box { public int $n = 0; public string $s = ""; }');
};

$toThread   = new ThreadChannel(4);
$fromThread = new ThreadChannel(4);

spawn(function () use ($toThread, $fromThread, $boot) {
    $thread = spawn_thread(function () use ($toThread, $fromThread) {
        $obj = $toThread->recv();
        // var_dump in the WORKER materializes the table before sending back
        ob_start(); var_dump($obj); ob_end_clean();
        $obj->n += 1;
        $fromThread->send($obj);
    }, bootloader: $boot);

    $obj = new Box();
    $obj->n = 41;
    $obj->s = "hello";
    // var_dump in MAIN materializes the table before the first send
    ob_start(); var_dump($obj); ob_end_clean();
    $toThread->send($obj);

    $back = $fromThread->recv();
    echo "back: n={$back->n} s={$back->s}\n";
    echo "original unchanged: n={$obj->n} (deep copy)\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
back: n=42 s=hello
original unchanged: n=41 (deep copy)
Done
