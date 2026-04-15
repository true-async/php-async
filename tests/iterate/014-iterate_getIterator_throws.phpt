--TEST--
Async\iterate: IteratorAggregate::getIterator() throwing propagates and aborts iterate()
--FILE--
<?php

use function Async\iterate;
use function Async\spawn;
use function Async\await;

// Covers async.c L856-867: the `Z_OBJCE_P(iterable)->get_iterator()` call
// in Async\iterate() — when the iterator factory throws, iterate() must
// re-throw before spawning any child coroutine.

class BadAggregate implements \IteratorAggregate
{
    public function getIterator(): \Iterator
    {
        throw new \LogicException("no iterator for you");
    }
}

$coroutine = spawn(function() {
    try {
        iterate(new BadAggregate(), function($v, $k) {
            echo "should-not-run: $v\n";
        });
        echo "no-throw\n";
    } catch (\LogicException $e) {
        echo "caught: ", $e->getMessage(), "\n";
    }
});

await($coroutine);

?>
--EXPECT--
caught: no iterator for you
