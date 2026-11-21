--TEST--
ThreadPool: a value the bootloader writes to $_ENV survives the files it requires
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
?>
--INI--
variables_order=EGPCS
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

// An HTTP server's bootloader sets $_ENV, then requires its autoloader and
// bootstrap file; those are the first code compiled in the worker.
$file = __DIR__ . '/104-bootloader_superglobal_write_survives_require.inc';
file_put_contents($file, '<?php $GLOBALS["boot_seen"] = $_ENV["ASYNC_104"] ?? "missing";');

spawn(function () use ($file) {
    $boot = function () use ($file) {
        $_ENV['ASYNC_104'] = 'boot';
        require $file;
    };
    $pool = new ThreadPool(1, 0, $boot);

    echo "bootloader saw: ", await($pool->submit(fn() => $GLOBALS['boot_seen'])), "\n";
    echo "task sees: ", await($pool->submit(fn() => $_ENV['ASYNC_104'] ?? 'missing')), "\n";

    $pool->close();
});
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/104-bootloader_superglobal_write_survives_require.inc');
?>
--EXPECT--
bootloader saw: boot
task sees: boot
