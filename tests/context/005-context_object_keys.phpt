--TEST--
Context with object keys
--FILE--
<?php

use Async\{Context};

$context = Async\coroutineContext();

// Test different object types as keys
$stdObj = new stdClass();
$stdObj->id = 1;

$dateObj = new DateTime('2024-01-01');

class TestClass {
    public $value = 'test';
}
$testObj = new TestClass();

// Set values with object keys
$context->set($stdObj, 'stdClass_value');
$context->set($dateObj, 'DateTime_value');
$context->set($testObj, 'TestClass_value');

// Retrieve values
var_dump($context->get($stdObj));
var_dump($context->get($dateObj));
var_dump($context->get($testObj));

// Test has with object keys
var_dump($context->has($stdObj));
var_dump($context->has($dateObj));
var_dump($context->has($testObj));

// Test with different instance of same class (should not match)
$anotherStdObj = new stdClass();
$anotherStdObj->id = 1;
var_dump($context->has($anotherStdObj)); // Should be false

// Test unset with object key
$result = $context->unset($stdObj);
var_dump($result instanceof Context);
var_dump($context->has($stdObj)); // Should be false now

// Test that other object keys still exist
var_dump($context->has($dateObj));
var_dump($context->has($testObj));

?>
--EXPECT--
string(14) "stdClass_value"
string(14) "DateTime_value"
string(15) "TestClass_value"
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)