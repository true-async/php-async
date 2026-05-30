--TEST--
ThreadPool: submit() $this is a BackedEnum case; access through $this works
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

enum Suit: string {
    case H = 'h';
    case S = 's';

    public function go(ThreadPool $pool): string {
        $f = $pool->submit(function() {
            return $this->value . ":" . $this->name;
        });
        return await($f);
    }
}

$boot = function() {
    eval('enum Suit: string { case H = "h"; case S = "s"; }');
};

spawn(function() use ($boot) {
    $pool = new ThreadPool(2, 0, $boot);
    echo "result=", Suit::H->go($pool), "\n";
    $pool->close();
});
?>
--EXPECT--
result=h:H
