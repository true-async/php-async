--TEST--
Context error handling
--FILE--
<?php

use Async\{Context};

$context = Async\coroutineContext();

// Test invalid key types
try {
    $context->set(123, 'value'); // Invalid key type
} catch (Async\AsyncException $e) {
    echo "Error for integer key: " . $e->getMessage() . "\n";
}

try {
    $context->set([], 'value'); // Invalid key type
} catch (Async\AsyncException $e) {
    echo "Error for array key: " . $e->getMessage() . "\n";
}

try {
    $context->get(123); // Invalid key type
} catch (Async\AsyncException $e) {
    echo "Error for get with integer key: " . $e->getMessage() . "\n";
}

try {
    $context->has(null); // Invalid key type
} catch (Async\AsyncException $e) {
    echo "Error for has with null key: " . $e->getMessage() . "\n";
}

try {
    $context->unset(3.14); // Invalid key type
} catch (Async\AsyncException $e) {
    echo "Error for unset with float key: " . $e->getMessage() . "\n";
}

// Test valid operations after errors
$context->set('valid_key', 'valid_value');
var_dump($context->get('valid_key'));

?>
--EXPECT--
Error for integer key: Context key must be a string or an object
Error for array key: Context key must be a string or an object
Error for get with integer key: Context key must be a string or an object
Error for has with null key: Context key must be a string or an object
Error for unset with float key: Context key must be a string or an object
string(11) "valid_value"