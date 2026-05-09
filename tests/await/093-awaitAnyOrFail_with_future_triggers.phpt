--TEST--
await_any_or_fail() with Future triggers — every position must wake the awaiter
--DESCRIPTION--
Regression test for #103. The previous bug: passing an array of Futures to
await_any_or_fail() only wired up the listener for the first array slot.
Completing any later Future left the awaiter suspended forever.

We exercise every position individually: in turn, complete F1 (slot 0),
F2 (slot 1) and F3 (slot 2). Each must wake await_any_or_fail() and
deliver the right value.
--FILE--
<?php

use Async\Future;
use Async\FutureState;
use function Async\spawn;
use function Async\await_all;

function run_case(int $winner): void {
    $states = [new FutureState(), new FutureState(), new FutureState()];
    $futures = [new Future($states[0]), new Future($states[1]), new Future($states[2])];

    $producer = spawn(function() use ($states, $winner) {
        $states[$winner]->complete($winner + 100);
    });
    $awaiter = spawn(function() use ($futures, $winner) {
        $r = \Async\await_any_or_fail($futures);
        echo "winner=$winner got=$r\n";
    });

    await_all([$producer, $awaiter]);

    foreach ($futures as $f) {
        $f->ignore();
    }
}

run_case(0);
run_case(1);
run_case(2);

echo "done\n";
?>
--EXPECT--
winner=0 got=100
winner=1 got=101
winner=2 got=102
done
