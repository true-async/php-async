--TEST--
spawn_thread() — fwrite(STDERR, ...) reaches the parent stderr
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
    $t = spawn_thread(static function (): int {
        return fwrite(STDERR, "hello-from-child-thread\n");
    });
    $written = await($t);
    fprintf(STDOUT, "main: written=%d\n", $written);
});
?>
--EXPECTF--
hello-from-child-thread
main: written=24
