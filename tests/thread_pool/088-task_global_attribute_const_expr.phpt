--TEST--
ThreadPool: an attribute argument written as a constant expression survives its task
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
    public const LABEL = 'const-expr-label';

    public function __construct(public string $label = '') {}
}

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

// Such an argument is kept as an unevaluated tree until something asks for it,
// and that tree lived in the snapshot arena. Unlike the plain cases this one
// does not fail on the old code — the freed tree still read as itself, and
// valgrind reported nothing — so it records the contract rather than catching
// the defect.
spawn(function() {
    $pool = new ThreadPool(workers: 1, coroutine: true);

    await($pool->submit(static function() {
        $GLOBALS['tagged'] = #[Marker(Marker::LABEL . '-suffix')] static function() { return 1; };
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
string(30) "Marker:const-expr-label-suffix"
Done
