--TEST--
Chaos: N independent coroutines all complete, output is a multiset
--DESCRIPTION--
Spawns 20 coroutines that each print one line. Under any scheduler interleaving
(FIFO, random:N, pct:...) every coroutine must run to completion exactly once,
so the output is the same multiset of lines regardless of order.

Run with: TRUE_ASYNC_SCHED=random:N make TESTS=ext/async/tests/chaos test
--FILE--
<?php
use function Async\spawn;
use function Async\await_all;

$coros = [];
for ($i = 0; $i < 20; $i++) {
    $coros[] = spawn(function() use ($i) {
        echo "coro $i done\n";
    });
}
await_all($coros);
echo "main done\n";
?>
--EXPECT_UNORDERED--
coro 0 done
coro 1 done
coro 2 done
coro 3 done
coro 4 done
coro 5 done
coro 6 done
coro 7 done
coro 8 done
coro 9 done
coro 10 done
coro 11 done
coro 12 done
coro 13 done
coro 14 done
coro 15 done
coro 16 done
coro 17 done
coro 18 done
coro 19 done
main done
