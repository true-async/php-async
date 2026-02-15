--TEST--
Context error handling
--FILE--
<?php

use Async\{Context};

$context = Async\coroutine_context();

// Test invalid key types
try {
    $context->set(123, 'value'); // Invalid key type
} catch (\TypeError $e) {
    echo "Error for integer key: " . $e->getMessage() . "\n";
}

try {
    $context->set([], 'value'); // Invalid key type
} catch (\TypeError $e) {
    echo "Error for array key: " . $e->getMessage() . "\n";
}

try {
    $context->get(123); // Invalid key type
} catch (\TypeError $e) {
    echo "Error for get with integer key: " . $e->getMessage() . "\n";
}

try {
    $context->has(null); // Invalid key type
} catch (\TypeError $e) {
    echo "Error for has with null key: " . $e->getMessage() . "\n";
}

try {
    $context->unset(3.14); // Invalid key type
} catch (\TypeError $e) {
    echo "Error for unset with float key: " . $e->getMessage() . "\n";
}

// Test valid operations after errors
$context->set('valid_key', 'valid_value');
var_dump($context->get('valid_key'));

?>
--EXPECT--
Error for integer key: Async\Context::set(): Argument #1 ($key) must be of type string|object, int given
Error for array key: Async\Context::set(): Argument #1 ($key) must be of type string|object, array given
Error for get with integer key: Async\Context::get(): Argument #1 ($key) must be of type string|object, int given
Error for has with null key: Async\Context::has(): Argument #1 ($key) must be of type string|object, null given
Error for unset with float key: Async\Context::unset(): Argument #1 ($key) must be of type string|object, float given
string(11) "valid_value"
