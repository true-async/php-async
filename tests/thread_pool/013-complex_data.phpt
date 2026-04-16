--TEST--
ThreadPool: complex data types in submit/result
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

spawn(function() {
    $pool = new ThreadPool(2);

    $future = $pool->submit(function(array $data) {
        return [
            'sum' => array_sum($data['values']),
            'name' => strtoupper($data['name']),
        ];
    }, ['name' => 'test', 'values' => [1, 2, 3, 4, 5]]);

    $result = await($future);
    echo "sum: " . $result['sum'] . "\n";
    echo "name: " . $result['name'] . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
sum: 15
name: TEST
Done
