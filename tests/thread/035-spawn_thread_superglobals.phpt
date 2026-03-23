--TEST--
spawn_thread() - parent custom globals not visible in thread
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
        return [
            'has_test_var' => isset($GLOBALS['test_var']),
        ];
    }));

    echo "has_test_var: " . ($result['has_test_var'] ? 'yes' : 'no') . "\n";
});
?>
--EXPECT--
has_test_var: no
