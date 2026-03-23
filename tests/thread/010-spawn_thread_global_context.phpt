--TEST--
spawn_thread() - works without coroutine wrapper (global context)
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn_thread;
use function Async\await;

$thread = spawn_thread(function() {
    return 'hello from thread';
});

$result = await($thread);
echo $result . "\n";
echo "done\n";
?>
--EXPECT--
hello from thread
done
