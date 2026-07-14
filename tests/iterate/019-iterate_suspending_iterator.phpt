--TEST--
iterate() - an iterator that suspends inside its own methods is still advanced safely
--FILE--
<?php

use function Async\spawn;
use function Async\delay;
use function Async\iterate;

// Suspending inside rewind()/current()/next() is what ITERATOR_SAFE_MOVING guards against: while the
// position is undefined no worker may be spawned. Uncancelling the microtask at the end of a move must
// not weaken that -- every value has to be delivered exactly once.
final class SuspendingIterator implements Iterator
{
    private int $i = 1;

    public function __construct(private int $n) {}

    public function rewind(): void { delay(5); $this->i = 1; }

    public function valid(): bool { return $this->i <= $this->n; }

    public function current(): mixed { delay(5); return $this->i; }

    public function key(): mixed { return $this->i; }

    public function next(): void { delay(5); $this->i++; }
}

echo "start\n";

spawn(function () {
    foreach ([1, 3] as $concurrency) {
        $active = 0;
        $peak = 0;
        $seen = [];

        iterate(new SuspendingIterator(6), function ($value) use (&$active, &$peak, &$seen) {
            $active++;

            if ($active > $peak) {
                $peak = $active;
            }

            delay(20);
            $seen[] = $value;
            $active--;
        }, concurrency: $concurrency);

        sort($seen);
        echo "concurrency $concurrency: values ", implode(',', $seen),
             ", within limit: ", var_export($peak <= $concurrency, true), "\n";
    }

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
concurrency 1: values 1,2,3,4,5,6, within limit: true
concurrency 3: values 1,2,3,4,5,6, within limit: true
done
