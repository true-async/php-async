--TEST--
RemoteFuture: error() with various exception types
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    // Test with RuntimeException
    $state1 = new FutureState();
    $future1 = new Future($state1);

    $t1 = spawn_thread(function() use ($state1) {
        $state1->error(new \RuntimeException("runtime error", 42));
    });

    try {
        await($future1);
    } catch (\RuntimeException $e) {
        echo "RuntimeException: " . $e->getMessage() . " code=" . $e->getCode() . "\n";
    }
    await($t1);

    // Test with InvalidArgumentException
    $state2 = new FutureState();
    $future2 = new Future($state2);

    $t2 = spawn_thread(function() use ($state2) {
        $state2->error(new \InvalidArgumentException("bad arg"));
    });

    try {
        await($future2);
    } catch (\InvalidArgumentException $e) {
        echo "InvalidArgumentException: " . $e->getMessage() . "\n";
    }
    await($t2);

    echo "Done\n";
});
?>
--EXPECT--
RuntimeException: runtime error code=42
InvalidArgumentException: bad arg
Done
