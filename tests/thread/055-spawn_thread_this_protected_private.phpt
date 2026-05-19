--TEST--
spawn_thread() - $this access to protected/private properties (user's original repro)
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
    public int $pub = 0;
    protected string $prot = "default";
    private int $priv = 0;

    public function run(\Closure $boot): array {
        $t = spawn_thread(function(): array {
            return [
                'pub'  => $this->pub,
                'prot' => $this->prot,
                'priv' => $this->priv,
            ];
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('class C { public int $pub = 0; protected string $prot = "default"; private int $priv = 0; }');
};

spawn(function() use ($boot) {
    $c = new C();
    (function() { $this->pub = 1; $this->prot = "set"; $this->priv = 42; })->call($c);
    $r = $c->run($boot);
    foreach ($r as $k => $v) echo "$k=$v\n";
});
?>
--EXPECT--
pub=1
prot=set
priv=42
