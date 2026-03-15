--TEST--
spawn_thread() - autoloaders are cloned to child thread
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

spl_autoload_register(function(string $class) {
    // Simple autoloader that defines class dynamically
    if ($class === 'AutoloadedClass') {
        eval('class AutoloadedClass { public function hello(): string { return "autoloaded!"; } }');
    }
});

spawn(function() {
    $thread = spawn_thread(function() {
        // Autoloader should be available in child thread
        $loaders = spl_autoload_functions();
        echo "autoloaders count: " . count($loaders) . "\n";

        $obj = new AutoloadedClass();
        echo $obj->hello() . "\n";
    });

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
autoloaders count: 1
autoloaded!
done
