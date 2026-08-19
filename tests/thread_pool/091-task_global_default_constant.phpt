--TEST--
ThreadPool: a default written as a constant is still resolvable after its task
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

// A default value that names a constant is compiled into an unevaluated tree
// and resolved on the first call. The tree lived in the snapshot arena, so a
// closure called after its task read it from memory that had been handed out.
spawn(function() {
    $pool = new ThreadPool(
        workers: 1,
        bootloader: static fn() => define('TP_RETRIES', 'retries-constant-value'),
        coroutine: true,
    );

    await($pool->submit(static function() {
        $GLOBALS['f'] = static function($n = TP_RETRIES) { return $n; };
        return 'stored';
    }));

    await($pool->submit(static function() {
        $junk = [];
        for ($i = 0; $i < 30000; $i++) { $junk[] = "filler $i"; }
        return count($junk);
    }));

    var_dump(await($pool->submit(static function() {
        $f = $GLOBALS['f'] ?? null;
        return $f === null ? 'gone' : $f();
    })));

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
string(22) "retries-constant-value"
Done
