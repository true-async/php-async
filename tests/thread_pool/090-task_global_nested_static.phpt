--TEST--
ThreadPool: the static of a nested closure keeps its own string
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

// The static variables of a nested definition were duplicated shallowly, so
// their keys and string values stayed in the snapshot arena and travelled out
// of the task inside whatever the closure returned.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    await($pool->submit(static function() {
        $GLOBALS['v'] = (static function() {
            static $seed = 'nested-static-seed';
            return $seed;
        })();

        return 'stored';
    }));

    // Allocation pressure, so the freed arena block is handed out again.
    await($pool->submit(static function() {
        $junk = [];
        for ($i = 0; $i < 30000; $i++) { $junk[] = "filler $i"; }
        return count($junk);
    }));

    var_dump(await($pool->submit(static fn() => $GLOBALS['v'] ?? 'gone')));

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
string(18) "nested-static-seed"
Done
