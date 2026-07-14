--TEST--
iterate() - a callback slower than the rest is not cancelled on completion
--FILE--
<?php

use function Async\spawn;
use function Async\delay;
use function Async\iterate;

echo "start\n";

// The last item outlives the others, so the iteration loop runs dry while it is still parked.
// It must be awaited, not treated as a leftover and cancelled by cancelPending.
spawn(function () {
    $started = 0;
    $finished = 0;
    $cancelled = 0;

    iterate([1, 2, 3, 4], function ($value) use (&$started, &$finished, &$cancelled) {
        $started++;

        try {
            delay($value === 4 ? 120 : 10);
        } catch (Throwable $exception) {
            $cancelled++;
            throw $exception;
        }

        $finished++;
    }, concurrency: 2);

    echo "started: $started\n";
    echo "finished: $finished\n";
    echo "cancelled: $cancelled\n";
    echo "done\n";
});

echo "end\n";
?>
--EXPECT--
start
end
started: 4
finished: 4
cancelled: 0
done
