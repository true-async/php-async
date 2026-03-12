--TEST--
spawn() with __call trampoline callable
--FILE--
<?php

use function Async\spawn;

class TrampolineTest {
    public function __call(string $name, array $arguments) {
        echo 'Trampoline for ', $name, PHP_EOL;
        return 'result';
    }
}

$o = new TrampolineTest();
spawn([$o, 'myMethod']);
?>
--EXPECT--
Trampoline for myMethod
