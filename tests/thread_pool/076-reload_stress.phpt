--TEST--
ThreadPool - repeated reload() under submit load: every task resolves, pool stays healthy
--FILE--
<?php

use Async\ThreadPool;
use function Async\await;

// Covers S6/S7-adjacent churn too: rotations overlap task traffic constantly.
// (A deterministic bailout-mid-reload cannot be staged from a phpt.)
$pool = new ThreadPool(4);

$futures = [];
$expected = 0;

for ($r = 0; $r < 10; $r++) {
    for ($i = 0; $i < 20; $i++) {
        $v = $r * 100 + $i;
        $expected += $v;
        $futures[] = $pool->submit(fn() => $v);
    }
    $pool->reload();
}

$sum = 0;
foreach ($futures as $f) {
    $sum += await($f);
}

var_dump(count($futures) === 200);
var_dump($sum === $expected);                          // no task lost across 10 rotations
var_dump(await($pool->submit(fn() => 'ok')) === 'ok'); // pool serves after the churn

$pool->close();

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
done
