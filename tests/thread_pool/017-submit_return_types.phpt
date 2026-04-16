--TEST--
ThreadPool: submit returns various types correctly
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

    echo "int: " . await($pool->submit(fn() => 42)) . "\n";
    echo "float: " . await($pool->submit(fn() => 3.14)) . "\n";
    echo "string: " . await($pool->submit(fn() => "hello")) . "\n";
    echo "bool: " . (await($pool->submit(fn() => true)) ? "true" : "false") . "\n";
    echo "null: " . var_export(await($pool->submit(fn() => null)), true) . "\n";
    echo "array: " . implode(",", await($pool->submit(fn() => [1, 2, 3]))) . "\n";

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
int: 42
float: 3.14
string: hello
bool: true
null: NULL
array: 1,2,3
Done
