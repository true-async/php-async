--TEST--
fclose() is refused while a read filter is parked, after a write filter has left
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

/* The mirror of 104: the write filter leaves first and must not lift the close
 * guard the parked read filter still needs. A socket, since a write to a file
 * also takes the read side the parked reader holds. */
final class Sync
{
    public static bool $writerIn = false;
    public static bool $readerIn = false;
    public static bool $writerOut = false;
    public static bool $closeTried = false;
}

class WriteGate extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        if (!$closing) {
            Sync::$writerIn = true;

            gate('readerIn', fn() => Sync::$readerIn);
        }

        return PSFS_PASS_ON;
    }
}

class ReadGate extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        Sync::$readerIn = true;

        gate('closeTried', fn() => Sync::$closeTried);

        return PSFS_PASS_ON;
    }
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

stream_filter_register('write-gate', 'WriteGate');
stream_filter_register('read-gate', 'ReadGate');

[$handle, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fwrite($peer, str_repeat('x', 10));

stream_filter_append($handle, 'write-gate', STREAM_FILTER_WRITE);
stream_filter_append($handle, 'read-gate', STREAM_FILTER_READ);

$writer = spawn(function () use ($handle) {
    $written = fwrite($handle, 'abcdefgh');
    Sync::$writerOut = true;
    return $written;
});

$reader = spawn(function () use ($handle) {
    gate('writerIn', fn() => Sync::$writerIn);
    return strlen((string) fread($handle, 10));
});

$closer = spawn(function () use ($handle) {
    gate('writerOut', fn() => Sync::$writerOut);
    $closed = fclose($handle);
    Sync::$closeTried = true;
    return $closed;
});

[$results, $exceptions] = await_all([$writer, $reader, $closer]);

echo "write: ", var_export($results[0], true), "\n";
echo "read: ", var_export($results[1], true), "\n";
echo "close while parked: ", var_export($results[2], true), "\n";
echo "Exceptions: ", count($exceptions), "\n";
echo "close after: ", var_export(fclose($handle), true), "\n";
echo "peer got: ", strlen((string) fread($peer, 100)), "\n";

fclose($peer);

?>
--EXPECTF--
Warning: fclose(): cannot close the provided stream, as it must not be manually closed in %s on line %d
write: 8
read: 10
close while parked: false
Exceptions: 0
close after: true
peer got: 8
