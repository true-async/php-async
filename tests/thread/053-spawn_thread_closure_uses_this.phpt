--TEST--
spawn_thread() - closure that binds $this transfers $this as a deep copy
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
    public string $var1 = "default";
    public int $n = 0;

    public function run(\Closure $boot): int {
        $t = spawn_thread(function() {
            echo "worker: var1={$this->var1} n={$this->n} class=", get_class($this), "\n";
            $this->n = 999;
            return $this->n;
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public string $var1 = "default"; public int $n = 0; }');
};

spawn(function() use ($boot) {
    $obj = new C();
    $obj->var1 = "from parent";
    $obj->n = 7;
    $r = $obj->run($boot);
    echo "result=$r\n";
    echo "parent n={$obj->n}\n";
});
?>
--EXPECT--
worker: var1=from parent n=7 class=C
result=999
parent n=7
