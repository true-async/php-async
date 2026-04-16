--TEST--
spawn_thread() - return all scalar types
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
    $results = [];

    $results[] = await(spawn_thread(function() { return true; }));
    $results[] = await(spawn_thread(function() { return false; }));
    $results[] = await(spawn_thread(function() { return null; }));
    $results[] = await(spawn_thread(function() { return 3.14; }));
    $results[] = await(spawn_thread(function() { return 0; }));
    $results[] = await(spawn_thread(function() { return ""; }));
    $results[] = await(spawn_thread(function() { return PHP_INT_MAX; }));

    foreach ($results as $value) {
        var_dump($value);
    }
});
?>
--EXPECT--
bool(true)
bool(false)
NULL
float(3.14)
int(0)
string(0) ""
int(9223372036854775807)
