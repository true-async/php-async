--TEST--
spawn_thread() - captured array contains closure values invoked inside thread
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

spawn(function() {
    $handlers = [
        'a' => static fn(int $x): int => $x + 1,
        'b' => static fn(int $x): int => $x * 10,
    ];

    $thread = spawn_thread(static function() use ($handlers): array {
        $out = [];
        foreach ($handlers as $key => $fn) {
            $out[$key] = $fn(5);
        }
        return $out;
    });

    $result = await($thread);
    echo "a={$result['a']}\n";
    echo "b={$result['b']}\n";
});
?>
--EXPECT--
a=6
b=50
