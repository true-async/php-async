--TEST--
Context inheritance through coroutines
--FILE--
<?php

use Async\{spawn, suspend, Context};

$context = new Context();
$context->set('global_var', 'parent_value');
$context->set('local_var', 'parent_local');

// Test context inheritance in spawned coroutines
spawn(function() use ($context) {
    // Should find value from parent context
    var_dump($context->find('global_var'));
    
    // Set local value
    $context->set('local_var', 'child_local');
    $context->set('child_only', 'child_value');
    
    // Test local vs inherited
    var_dump($context->getLocal('local_var'));
    var_dump($context->findLocal('global_var')); // Should be null
    var_dump($context->find('global_var')); // Should find from parent
    
    spawn(function() use ($context) {
        // Nested coroutine inheritance
        var_dump($context->find('global_var'));
        var_dump($context->find('child_only'));
        $context->set('nested_var', 'nested_value');
    });
    
    suspend();
});

suspend();

// Parent context should not see child changes
var_dump($context->hasLocal('child_only')); // false
var_dump($context->getLocal('local_var')); // original value

?>
--EXPECT--
string(12) "parent_value"
string(12) "child_local"
NULL
string(12) "parent_value"
string(12) "parent_value"
string(11) "child_value"
bool(false)
string(12) "parent_local"