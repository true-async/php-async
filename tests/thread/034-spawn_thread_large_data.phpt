--TEST--
spawn_thread() - transfer large data structures
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
    // Large string
    $r1 = await(spawn_thread(function() {
        return str_repeat('A', 100000);
    }));
    echo "string len: " . strlen($r1) . "\n";

    // Large array
    $r2 = await(spawn_thread(function() {
        return range(1, 1000);
    }));
    echo "array count: " . count($r2) . "\n";
    echo "array sum: " . array_sum($r2) . "\n";

    // Nested structure
    $r3 = await(spawn_thread(function() {
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = ['id' => $i, 'name' => "item_$i", 'values' => [$i, $i*2, $i*3]];
        }
        return $data;
    }));
    echo "nested count: " . count($r3) . "\n";
    echo "last: " . $r3[99]['name'] . "\n";
});
?>
--EXPECT--
string len: 100000
array count: 1000
array sum: 500500
nested count: 100
last: item_99
