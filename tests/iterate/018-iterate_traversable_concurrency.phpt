--TEST--
iterate() - concurrency applies to Traversable, not only to arrays
--FILE--
<?php

use function Async\spawn;
use function Async\delay;
use function Async\iterate;

function items(int $n): Generator
{
    for ($i = 1; $i <= $n; $i++) {
        yield $i;
    }
}

echo "start\n";

// Every move of a zend_iterator runs under ITERATOR_SAFE_MOVING, which cancels the spawning microtask.
// It used to stay cancelled whenever the move did not suspend, so no worker was ever spawned again and
// concurrency was silently a no-op for the whole Traversable half of `iterable`.
spawn(function () {
    foreach ([['array', fn() => range(1, 8)],
              ['ArrayIterator', fn() => new ArrayIterator(range(1, 8))],
              ['Generator', fn() => items(8)]] as [$name, $make]) {

        $active = 0;
        $peak = 0;
        $seen = [];

        iterate($make(), function ($value) use (&$active, &$peak, &$seen) {
            $active++;

            if ($active > $peak) {
                $peak = $active;
            }

            delay(20);
            $seen[] = $value;
            $active--;
        }, concurrency: 4);

        sort($seen);
        echo $name, ": peak $peak, values ", implode(',', $seen), "\n";
    }

    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
array: peak 4, values 1,2,3,4,5,6,7,8
ArrayIterator: peak 4, values 1,2,3,4,5,6,7,8
Generator: peak 4, values 1,2,3,4,5,6,7,8
done
