--TEST--
Async\iterate(): non-iterable argument throws TypeError
--FILE--
<?php

use function Async\iterate;

// Covers async.c:861-863 — Async_iterate() argument-type-error branch
// for arguments that are neither array nor Traversable.

echo "start\n";

try {
    iterate(42, fn($v) => null);
} catch (\TypeError $e) {
    echo "caught: " . $e->getMessage() . "\n";
}

echo "end\n";

?>
--EXPECTF--
start
caught: %A
end
