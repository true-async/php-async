--TEST--
root_context() basic usage and no memory leak
--FILE--
<?php

$ctx = Async\root_context();
var_dump($ctx instanceof Async\Context);

// Same object on second call
$ctx2 = Async\root_context();
var_dump($ctx === $ctx2);

echo "done\n";
?>
--EXPECT--
bool(true)
bool(true)
done
