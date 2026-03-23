--TEST--
spawn_thread() - parent globals not visible in thread
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

$GLOBALS['test_var'] = 'parent_value';

spawn(function() {
    $result = await(spawn_thread(function() {
        return isset($GLOBALS['test_var']);
    }));

    echo "parent globals isolated: " . ($result ? 'no' : 'yes') . "\n";
});
?>
--EXPECT--
parent globals isolated: yes
