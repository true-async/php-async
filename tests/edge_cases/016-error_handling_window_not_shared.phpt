--TEST--
An EH_THROW window does not follow a coroutine spawned inside it
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

/* DirectoryIterator::__construct turns warnings into UnexpectedValueException for
 * the duration of the directory open (zend_replace_error_handling), and the open
 * goes through a userland wrapper — so a coroutine can be spawned, and the holder
 * can park, while that mode is in force. The spawned coroutine is a separate flow
 * of control: its own warning must stay a warning, whatever the holder is doing.
 * PDO::__construct holds the same kind of window across its connect, which is how
 * this surfaced downstream, but a wrapper reproduces it without a database. */

class WindowedDirectory
{
    public static ?object $spawned = null;

    public $context;

    public function dir_opendir(string $path, int $options): bool
    {
        self::$spawned = spawn(static function (): string {
            try {
                return 'suppressed, got ' . var_export(@gzdecode(''), true);
            } catch (Throwable $e) {
                return 'leaked ' . $e::class . ': ' . $e->getMessage();
            }
        });

        delay(50);

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

$holder = spawn(static function (): string {
    try {
        new DirectoryIterator('windowed://dir');
    } catch (Throwable $e) {
        return 'holder: ' . $e::class;
    }

    return 'holder: no exception';
});

echo await($holder), "\n";
echo await(WindowedDirectory::$spawned), "\n";
echo "Done\n";
?>
--EXPECT--
holder: UnexpectedValueException
suppressed, got false
Done
