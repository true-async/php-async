--TEST--
spawn_thread() - return array with mixed keys
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
    $thread = spawn_thread(function() {
        return [
            'name' => 'test',
            'values' => [1, 2, 3],
            42 => 'numeric key',
            'nested' => ['a' => 'b', 'c' => [true, false, null]],
        ];
    });

    $result = await($thread);
    echo $result['name'] . "\n";
    echo implode(',', $result['values']) . "\n";
    echo $result[42] . "\n";
    echo $result['nested']['a'] . "\n";
    var_dump($result['nested']['c']);
});
?>
--EXPECT--
test
1,2,3
numeric key
b
array(3) {
  [0]=>
  bool(true)
  [1]=>
  bool(false)
  [2]=>
  NULL
}
