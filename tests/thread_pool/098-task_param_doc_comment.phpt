--TEST--
ThreadPool: a parameter doc comment does not follow the task across threads
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

// The doc comment of a parameter is an ordinary refcounted string of the
// submitting thread, and the arg_info copy carried the pointer over untouched,
// so the worker both read and released a string it never owned.
//
// The task comes from an eval'd unit that is dropped right after the submit:
// while the submitting script still holds the string the stale pointer reads
// correctly, and the defect is invisible.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    $factory = eval('return static function() {
        $GLOBALS["documented"] = static function (/** @param int the counter */ int $x = 7) { return $x; };
        return "stored";
    };');

    var_dump(await($pool->submit($factory)));

    unset($factory);
    gc_collect_cycles();

    await($pool->submit(static function() {
        $junk = [];
        for ($i = 0; $i < 30000; $i++) { $junk[] = "filler $i"; }
        return count($junk);
    }));

    var_dump(await($pool->submit(static function() {
        $fn = $GLOBALS['documented'] ?? null;

        if ($fn === null) {
            return 'gone';
        }

        return (new ReflectionFunction($fn))->getParameters()[0]->getDocComment();
    })));

    $pool->close();
    echo "Done\n";
});

?>
--EXPECT--
string(6) "stored"
string(29) "/** @param int the counter */"
Done
