--TEST--
spawn_thread() - opcode validation result is cached on op_array
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
    // The lambda's op_array is shared by every materialised Closure; the
    // second transfer hits the cached "ok" flag set on the first run.
    $factory = static fn(int $x): int => $x * 2;

    foreach ([3, 7, 11] as $n) {
        echo await(spawn_thread(static fn(): int => $factory($n))), "\n";
    }

    // Invalid closures re-throw on every transfer — cache only memoises success.
    $invalid = static function() {
        class _ThreadValidationCacheTestCls {}
    };
    foreach (range(1, 2) as $_) {
        try {
            spawn_thread($invalid);
        } catch (\Error $e) {
            echo "rejected: ", strstr($e->getMessage(), 'illegal'), "\n";
        }
    }
});
?>
--EXPECTF--
6
14
22
rejected: illegal class declaration at %s:%d
rejected: illegal class declaration at %s:%d
