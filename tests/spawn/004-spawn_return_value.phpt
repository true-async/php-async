--TEST--
Future: spawn() - returns Coroutine object
--FILE--
<?php

use function Async\spawn;
use Async\Coroutine;

echo "start\n";

$coroutine = spawn(function() {
    echo "coroutine executed\n";
    return "result";
});

var_dump($coroutine instanceof Coroutine);
var_dump(get_class($coroutine));

echo "end\n";
?>
--EXPECT--
start
bool(true)
string(15) "Async\Coroutine"
end
coroutine executed