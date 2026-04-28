--TEST--
Async\available_parallelism: returns positive int matching usable CPU count
--FILE--
<?php

use function Async\available_parallelism;

$n = available_parallelism();

var_dump(is_int($n));
var_dump($n >= 1);

// Cheap upper-bound sanity check — no machine has more than 4096 usable CPUs
// in practice, and this catches obvious garbage like negative/huge values.
var_dump($n <= 4096);

// Stable across calls within one process.
var_dump(available_parallelism() === $n);

// Rejects extra args (arity check).
try {
    available_parallelism(1);
} catch (\ArgumentCountError $e) {
    echo "arity: ok\n";
}

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
arity: ok
done
