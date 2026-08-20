--TEST--
ThreadPool: a literal array a task stored in $GLOBALS keeps its own strings
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

// zend_array_dup copies the buckets and stops there, so the keys and string
// values of a literal array stayed in the snapshot arena. Reading them after
// the arena was handed out again gave a garbage length: "tried to allocate
// 375714811215953 bytes".
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    await($pool->submit(static function() {
        $GLOBALS['arr'] = ['alpha' => 'beta', 'gamma' => 'delta'];
        return 'stored';
    }));


    $seen = await($pool->submit(static function() {
        $arr = $GLOBALS['arr'] ?? [];
        return implode(',', array_keys($arr)) . '|' . implode(',', $arr);
    }));

    var_dump($seen);

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
string(22) "alpha,gamma|beta,delta"
Done
