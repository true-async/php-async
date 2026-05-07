--TEST--
spawn_thread() - captured array of closures with built-in class-typed args
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
        'a' => static fn(\ArrayObject $o): int => count($o),
        'b' => static fn(\stdClass    $o): string => $o->name,
    ];

    $thread = spawn_thread(static function() use ($handlers): array {
        $out = [];
        $out[] = $handlers['a'](new \ArrayObject([1, 2, 3, 4]));
        $std = new \stdClass();
        $std->name = 'hello';
        $out[] = $handlers['b']($std);
        return $out;
    });

    foreach (await($thread) as $line) {
        echo $line . "\n";
    }
});
?>
--EXPECT--
4
hello
