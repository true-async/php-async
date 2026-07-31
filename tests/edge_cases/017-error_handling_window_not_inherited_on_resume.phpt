--TEST--
An EH_THROW window opened while we were parked does not follow us on resume
--EXTENSIONS--
zlib
--SKIPIF--
<?php
if (!function_exists('Async\spawn')) die("skip TrueAsync runtime not available\n");
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await;
use function Async\delay;

/* Companion to 016. There the victim is spawned while the window is already open,
 * so what protects it is starting outside one. Here it is parked before the window
 * exists and wakes up in the middle of it, so what protects it is the context
 * switch carrying the mode per fiber. */

class WindowedDirectory
{
    public $context;

    public function dir_opendir(string $path, int $options): bool
    {
        delay(100);

        return false;
    }

    public function dir_readdir(): string|false
    {
        return false;
    }

    public function dir_closedir(): bool
    {
        return true;
    }
}

stream_wrapper_register('windowed', WindowedDirectory::class);

$victim = spawn(static function (): string {
    delay(10);
    delay(60); // Wakes up while the holder sits inside the window.

    try {
        return 'suppressed, got ' . var_export(@gzdecode(''), true);
    } catch (Throwable $e) {
        return 'leaked ' . $e::class . ': ' . $e->getMessage();
    }
});

$holder = spawn(static function (): string {
    delay(30);

    try {
        new DirectoryIterator('windowed://dir');
    } catch (Throwable $e) {
        return 'holder: ' . $e::class;
    }

    return 'holder: no exception';
});

echo await($victim), "\n";
echo await($holder), "\n";
echo "Done\n";
?>
--EXPECT--
suppressed, got false
holder: UnexpectedValueException
Done
