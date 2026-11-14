--TEST--
fclose() is refused while a userspace stream_write() is parked, after stream_read() has left
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;
use function Async\suspend;

/* The wrapper's read and write run at once, each under its own side lock. The
 * read leaving must not lift the close guard the parked write still needs. */
final class Sync
{
    public static bool $readerIn = false;
    public static bool $writerIn = false;
    public static bool $readerOut = false;
    public static bool $closeTried = false;
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

class GateWrapper
{
    public $context;

    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        Sync::$readerIn = true;
        gate('writer in', fn() => Sync::$writerIn);
        return str_repeat('x', min($count, 10));
    }

    public function stream_write(string $data): int
    {
        Sync::$writerIn = true;
        gate('close tried', fn() => Sync::$closeTried);
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}

stream_wrapper_register('gate', 'GateWrapper');

$handle = fopen('gate://x', 'r+');

$reader = spawn(function () use ($handle) {
    $data = fread($handle, 10);
    Sync::$readerOut = true;
    return strlen((string) $data);
});

$writer = spawn(function () use ($handle) {
    gate('reader in', fn() => Sync::$readerIn);
    return fwrite($handle, 'abcdefgh');
});

$closer = spawn(function () use ($handle) {
    gate('reader out', fn() => Sync::$readerOut);
    $closed = fclose($handle);
    Sync::$closeTried = true;
    return $closed;
});

[$results, $exceptions] = await_all([$reader, $writer, $closer]);

echo "read: ", var_export($results[0], true), "\n";
echo "write: ", var_export($results[1], true), "\n";
echo "close while parked: ", var_export($results[2], true), "\n";
echo "Exceptions: ", count($exceptions), "\n";
echo "close after: ", var_export(fclose($handle), true), "\n";

?>
--EXPECTF--
Warning: fclose(): cannot close the provided stream, as it must not be manually closed in %s on line %d
read: 10
write: 8
close while parked: false
Exceptions: 0
close after: true
