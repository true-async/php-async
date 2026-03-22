--TEST--
spawn_thread() - bootloader closure runs before task
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $thread = spawn_thread(
        task: function() {
            // Bootloader should have set up the autoloader
            $loaders = spl_autoload_functions();
            echo "autoloaders: " . count($loaders) . "\n";
            echo "task executed\n";
        },
        inherit: false,
        bootloader: function() {
            spl_autoload_register(function(string $class) {
                // Custom autoloader set up by bootloader
            });
            echo "bootloader executed\n";
        }
    );

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
bootloader executed
autoloaders: 1
task executed
done
