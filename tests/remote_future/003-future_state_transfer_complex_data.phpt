--TEST--
RemoteFuture: FutureState transferred to thread, complex data types
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use Async\FutureState;
use Async\Future;
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $state = new FutureState();
    $future = new Future($state);

    $thread = spawn_thread(function() use ($state) {
        $state->complete([
            'name' => 'test',
            'values' => [1, 2, 3],
            'nested' => ['key' => 'value'],
        ]);
    });

    $result = await($future);
    echo "name: " . $result['name'] . "\n";
    echo "values: " . implode(",", $result['values']) . "\n";
    echo "nested.key: " . $result['nested']['key'] . "\n";

    await($thread);
    echo "Done\n";
});
?>
--EXPECT--
name: test
values: 1,2,3
nested.key: value
Done
