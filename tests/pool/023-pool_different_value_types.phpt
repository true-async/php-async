--TEST--
Pool: different value types - supports various PHP types
--FILE--
<?php

use Async\Pool;

// Integer pool
$intPool = new Pool(factory: fn() => 42);
$v = $intPool->tryAcquire();
echo "Integer: " . gettype($v) . " = $v\n";

// String pool
$strPool = new Pool(factory: fn() => "hello");
$v = $strPool->tryAcquire();
echo "String: " . gettype($v) . " = $v\n";

// Array pool
$arrPool = new Pool(factory: fn() => ['a' => 1, 'b' => 2]);
$v = $arrPool->tryAcquire();
echo "Array: " . gettype($v) . " = " . json_encode($v) . "\n";

// Float pool
$floatPool = new Pool(factory: fn() => 3.14);
$v = $floatPool->tryAcquire();
echo "Float: " . gettype($v) . " = $v\n";

// Boolean pool
$boolPool = new Pool(factory: fn() => true);
$v = $boolPool->tryAcquire();
echo "Bool: " . gettype($v) . " = " . ($v ? "true" : "false") . "\n";

echo "Done\n";
?>
--EXPECT--
Integer: integer = 42
String: string = hello
Array: array = {"a":1,"b":2}
Float: double = 3.14
Bool: boolean = true
Done
