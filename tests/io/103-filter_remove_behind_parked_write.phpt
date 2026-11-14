--TEST--
Removing a write filter while another coroutine is parked inside that chain
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

/* The writer parks inside the filter with the write side locked. The removal
 * queues behind that lock instead of reading the chain as being applied: the
 * chain is busy in another coroutine, not on the remover's own stack. */
final class Sync
{
    public static bool $writerIn = false;
    public static bool $removerQueued = false;
    public static bool $writerOut = false;
}

function gate(string $name, Closure $open): void
{
    $deadline = hrtime(true) + 5_000_000_000;

    while (!$open()) {
        if (hrtime(true) > $deadline) {
            echo "gate '$name' never opened\n";
            return;
        }
        suspend();
    }
}

class Parker extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        if (!$closing) {
            Sync::$writerIn = true;
            gate('remover queued', fn() => Sync::$removerQueued);
            /* A few more turns, so the remover is parked on the lock. */
            for ($i = 0; $i < 10; $i++) {
                suspend();
            }
        }

        return PSFS_PASS_ON;
    }
}

stream_filter_register('parker', 'Parker');

$handle = fopen('php://memory', 'r+');
$filter = stream_filter_append($handle, 'parker', STREAM_FILTER_WRITE);

$writer = spawn(function () use ($handle) {
    $written = fwrite($handle, 'hello');
    Sync::$writerOut = true;
    return $written;
});

$remover = spawn(function () use ($filter) {
    gate('writer in', fn() => Sync::$writerIn);
    Sync::$removerQueued = true;
    $removed = stream_filter_remove($filter);
    return [$removed, Sync::$writerOut];
});

[$results, $exceptions] = await_all([$writer, $remover]);

echo "write: ", var_export($results[0], true), "\n";
echo "removed: ", var_export($results[1][0], true), "\n";
echo "removed after the write: ", var_export($results[1][1], true), "\n";
echo "Exceptions: " . count($exceptions) . "\n";

fwrite($handle, ' world');
rewind($handle);
echo stream_get_contents($handle), "\n";

?>
--EXPECT--
write: 5
removed: true
removed after the write: true
Exceptions: 0
hello world
