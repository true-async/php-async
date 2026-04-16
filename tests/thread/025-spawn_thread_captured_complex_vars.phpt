--TEST--
spawn_thread() - closure captures complex types (array, float, nested)
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
    $config = ['host' => 'localhost', 'port' => 8080];
    $factor = 2.5;
    $nested = ['level1' => ['level2' => 'deep']];

    $thread = spawn_thread(function() use ($config, $factor, $nested) {
        return [
            'host' => $config['host'],
            'scaled' => $config['port'] * $factor,
            'deep' => $nested['level1']['level2'],
        ];
    });

    $result = await($thread);
    echo $result['host'] . "\n";
    echo $result['scaled'] . "\n";
    echo $result['deep'] . "\n";
});
?>
--EXPECT--
localhost
20200
deep
