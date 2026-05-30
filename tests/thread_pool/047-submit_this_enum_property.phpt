--TEST--
ThreadPool: submit() $this has an enum-typed property; value preserved across transfer
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!class_exists('Async\ThreadPool')) die('skip ThreadPool not available');
?>
--FILE--
<?php

use Async\ThreadPool;
use function Async\spawn;
use function Async\await;

enum Color: string {
    case R = 'r';
    case G = 'g';
}

class C {
    public Color $c = Color::R;

    public function go(ThreadPool $pool): string {
        $f = $pool->submit(function() {
            return $this->c->value;
        });
        return await($f);
    }
}

$boot = function() {
    eval('enum Color: string { case R = "r"; case G = "g"; } class C { public Color $c = Color::R; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    $c = new C();
    $c->c = Color::G;
    echo "result=", $c->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=g
