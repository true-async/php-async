--TEST--
Context basic functionality
--EXTENSION--
async
--FILE--
<?php

use Async\{spawn, suspend, Context};

// Test basic context creation and storage
$context = new Context();

// Test string key storage
$context->set('user_id', 123);
$context->set('username', 'test_user');

// Test object key storage
$key = new stdClass();
$context->set($key, 'object_value');

// Test retrieval
var_dump($context->get('user_id'));
var_dump($context->get('username'));
var_dump($context->get($key));

// Test has/hasLocal
var_dump($context->has('user_id'));
var_dump($context->hasLocal('user_id'));
var_dump($context->has('non_existent'));

// Test unset
$result = $context->unset('username');
var_dump($result instanceof Context);
var_dump($context->has('username'));

// Test replace parameter
try {
    $context->set('user_id', 456, false); // Should throw error
} catch (Error $e) {
    echo "Expected error: " . $e->getMessage() . "\n";
}

$context->set('user_id', 456, true); // Should work
var_dump($context->get('user_id'));

?>
--EXPECT--
int(123)
string(9) "test_user"
string(12) "object_value"
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
Expected error: Context key already exists and replace is false
int(456)