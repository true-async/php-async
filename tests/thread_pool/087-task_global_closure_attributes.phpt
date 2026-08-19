--TEST--
ThreadPool: attributes of a closure a task stored in $GLOBALS stay readable
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

#[Attribute]
class Marker
{
    public function __construct(public string $label = '') {}
}

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

// The attribute table was copied into the snapshot arena and marked immutable
// with no destructor, so nothing localized it and nothing freed it: reflection
// on a closure that outlived its task read a freed header and asserted.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    await($pool->submit(static function() {
        $GLOBALS['tagged'] = #[Marker('tagged-closure')] static function() { return 1; };
        return 'stored';
    }));

    await($pool->submit(static function() {
        $junk = [];
        for ($i = 0; $i < 30000; $i++) { $junk[] = "filler $i"; }
        return count($junk);
    }));

    $seen = await($pool->submit(static function() {
        $fn = $GLOBALS['tagged'] ?? null;

        if ($fn === null) {
            return 'gone';
        }

        $attributes = (new ReflectionFunction($fn))->getAttributes();

        return $attributes === []
            ? 'no attributes'
            : $attributes[0]->getName() . ':' . ($attributes[0]->getArguments()[0] ?? '?');
    }));

    var_dump($seen);

    $pool->close();
    echo "Done\n";
});
?>
--EXPECT--
string(21) "Marker:tagged-closure"
Done
