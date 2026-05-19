--TEST--
spawn_thread() - $this has an enum-typed property; identity preserved
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

enum Status: int { case Open = 1; case Closed = 2; }

class Box {
    public Status $status = Status::Open;
    public function run(\Closure $boot): string {
        $t = spawn_thread(function(): string {
            $isEnum = ($this->status instanceof Status) ? 'yes' : 'no';
            $same = ($this->status === Status::Open) ? 'yes' : 'no';
            return "enum=$isEnum same=$same val=".$this->status->value;
        }, bootloader: $boot);
        return await($t);
    }
}

$boot = function() {
    eval('enum Status: int { case Open = 1; case Closed = 2; }');
    eval('class Box { public Status $status = Status::Open; }');
};

spawn(function() use ($boot) {
    $b = new Box();
    $b->status = Status::Open;
    echo $b->run($boot), "\n";
});
?>
--EXPECT--
enum=yes same=yes val=1
