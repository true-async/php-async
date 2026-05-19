--TEST--
spawn_thread() - $this->method() dispatch inside transferred closure
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
    public int $base = 10;
    protected function bump(int $by): int { return $this->base + $by; }
    public function run(\Closure $boot): int {
        $t = spawn_thread(function(): int { return $this->bump(5); }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public int $base = 0; protected function bump(int $by): int { return $this->base + $by; } }');
};

spawn(function() use ($boot) {
    echo (new C)->run($boot), "\n";
});
?>
--EXPECT--
15
