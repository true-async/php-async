--TEST--
spawn_thread() - backed enum values can be transferred
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
    // Backed enums serialize to their scalar value
    $r = await(spawn_thread(function() {
        return ['int' => 42, 'str' => 'hello', 'bool' => true, 'float' => 3.14];
    }));
    var_dump($r['int']);
    var_dump($r['str']);
    var_dump($r['bool']);
    var_dump($r['float']);
});
?>
--EXPECT--
int(42)
string(5) "hello"
bool(true)
float(3.14)
