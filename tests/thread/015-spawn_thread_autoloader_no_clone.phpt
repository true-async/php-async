--TEST--
spawn_thread() - autoloaders cloned even with inherit: false
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
    if ($class === 'LazyClass') {
        eval('class LazyClass { public function name(): string { return "lazy"; } }');
    }
});

spawn(function() {
    // inherit: false should still clone autoloaders
    $thread = spawn_thread(function() {
        $loaders = spl_autoload_functions();
        echo "has autoloaders: " . (count($loaders) > 0 ? "yes" : "no") . "\n";

        $obj = new LazyClass();
        echo $obj->name() . "\n";
    }, inherit: false);

    await($thread);
    echo "done\n";
});
?>
--EXPECT--
has autoloaders: yes
lazy
done
