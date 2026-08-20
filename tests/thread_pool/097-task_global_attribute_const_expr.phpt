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
    public function __construct(public string $label = '') {}
}

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

// Such an argument is kept as an unevaluated tree until something asks for it,
// and the table holding it lived in the snapshot arena. The name the tree holds
// is a constant defined at run time, which the compiler cannot fold away, and
// the bootloader gives the worker the same constant, so what reaches the tree
// is the worker's own value.
spawn(function() {
    $pool = new ThreadPool(
        workers: 1,
        bootloader: static fn() => define('TP_CONST_EXPR_LABEL', 'const-expr-label'),
        coroutine: true,
    );

    await($pool->submit(static function() {
        $GLOBALS['tagged'] = #[Marker(TP_CONST_EXPR_LABEL . '-suffix')] static function() { return 1; };
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
