--TEST--
spawn_thread() - a superglobal written by the closure survives the first compile in the thread
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--INI--
variables_order=EGPCS
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

// The closure is compiled here, in the parent. The file it requires is the
// first code compiled in the thread and reads the same superglobals.
$file = __DIR__ . '/082-spawn_thread_superglobal_write_survives_compile.inc';
file_put_contents($file, '<?php return [$_ENV["ASYNC_082"] ?? "missing", $_SERVER["ASYNC_082"] ?? "missing"];');

spawn(function () use ($file) {
    $result = await(spawn_thread(function () use ($file) {
        $_ENV['ASYNC_082'] = 'env';
        $_SERVER['ASYNC_082'] = 'server';

        return require $file;
    }));

    echo "env: {$result[0]}\n";
    echo "server: {$result[1]}\n";

    $nested = await(spawn_thread(function () use ($file) {
        $write = function () {
            $_ENV['ASYNC_082'] = 'nested';
        };
        $write();

        return (require $file)[0];
    }));

    echo "nested: {$nested}\n";
});
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/082-spawn_thread_superglobal_write_survives_compile.inc');
?>
--EXPECT--
env: env
server: server
nested: nested
