--TEST--
spawn_thread() - returning a mutated $this from worker; parent unchanged
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

class C {
    public int $n = 1;
    public function run(\Closure $boot): \C {
        $t = spawn_thread(function(): \C {
            $this->n = 999;
            return $this;
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public int $n = 0; }');
};

spawn(function() use ($boot) {
    $obj = new C();
    $obj->n = 7;
    $returned = $obj->run($boot);
    echo "parent obj n=", $obj->n, "\n";
    echo "returned n=", $returned->n, "\n";
    echo "same? ", ($obj === $returned ? 'yes' : 'no'), "\n";
});
?>
--EXPECT--
parent obj n=7
returned n=999
same? no
