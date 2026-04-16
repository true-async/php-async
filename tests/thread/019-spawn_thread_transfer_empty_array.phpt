--TEST--
spawn_thread() - transfer empty array
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
    $result = await(spawn_thread(function() {
        return [];
    }));
    var_dump($result);
});
?>
--EXPECT--
array(0) {
}
