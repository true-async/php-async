--TEST--
Context inheritance through scope hierarchy
--FILE--
<?php

use function Async\spawn;

echo "start\n";

// Create parent scope
$parent_scope = new \Async\Scope();

// Spawn coroutine in parent scope to set context values
$parent_coroutine = $parent_scope->spawn(function() {
    echo "parent coroutine started\n";
    
    $context = \Async\Coroutine::getCurrent()->getContext();
    
    // Set values in parent context
    $context['parent_key'] = 'parent_value';
    $context['shared_key'] = 'from_parent';
    
    echo "parent context values set\n";
    return "parent_done";
});

$parent_coroutine->getResult();

// Create child scope that inherits from parent
$child_scope = \Async\Scope::inherit($parent_scope);

// Test inheritance in child scope
$child_coroutine = $child_scope->spawn(function() {
    echo "child coroutine started\n";
    
    $context = \Async\Coroutine::getCurrent()->getContext();
    
    // Test find() - should find parent values
    echo "find parent_key: " . ($context->find('parent_key') ?: 'null') . "\n";
    echo "find shared_key: " . ($context->find('shared_key') ?: 'null') . "\n";
    
    // Test get() - should find parent values  
    echo "get parent_key: " . ($context->get('parent_key') ?: 'null') . "\n";
    echo "get shared_key: " . ($context->get('shared_key') ?: 'null') . "\n";
    
    // Test has() - should find parent values
    echo "has parent_key: " . ($context->has('parent_key') ? 'true' : 'false') . "\n";
    echo "has shared_key: " . ($context->has('shared_key') ? 'true' : 'false') . "\n";
    
    // Test findLocal() - should NOT find parent values
    echo "findLocal parent_key: " . ($context->findLocal('parent_key') ?: 'null') . "\n";
    echo "findLocal shared_key: " . ($context->findLocal('shared_key') ?: 'null') . "\n";
    
    // Test getLocal() - should NOT find parent values
    echo "getLocal parent_key: " . ($context->getLocal('parent_key') ?: 'null') . "\n";
    echo "getLocal shared_key: " . ($context->getLocal('shared_key') ?: 'null') . "\n";
    
    // Test hasLocal() - should NOT find parent values
    echo "hasLocal parent_key: " . ($context->hasLocal('parent_key') ? 'true' : 'false') . "\n";
    echo "hasLocal shared_key: " . ($context->hasLocal('shared_key') ? 'true' : 'false') . "\n";
    
    // Set local value that overrides parent
    $context['shared_key'] = 'from_child';
    $context['child_key'] = 'child_value';
    
    echo "child context values set\n";
    
    // Test override behavior
    echo "after override - find shared_key: " . ($context->find('shared_key') ?: 'null') . "\n";
    echo "after override - get shared_key: " . ($context->get('shared_key') ?: 'null') . "\n";
    echo "after override - findLocal shared_key: " . ($context->findLocal('shared_key') ?: 'null') . "\n";
    echo "after override - getLocal shared_key: " . ($context->getLocal('shared_key') ?: 'null') . "\n";
    
    // Test local-only value
    echo "findLocal child_key: " . ($context->findLocal('child_key') ?: 'null') . "\n";
    echo "getLocal child_key: " . ($context->getLocal('child_key') ?: 'null') . "\n";
    
    return "child_done";
});

$child_coroutine->getResult();

echo "end\n";

?>
--EXPECT--
start
parent coroutine started
parent context values set
child coroutine started
find parent_key: parent_value
find shared_key: from_parent
get parent_key: parent_value
get shared_key: from_parent
has parent_key: true
has shared_key: true
findLocal parent_key: null
findLocal shared_key: null
getLocal parent_key: null
getLocal shared_key: null
hasLocal parent_key: false
hasLocal shared_key: false
child context values set
after override - find shared_key: from_child
after override - get shared_key: from_child
after override - findLocal shared_key: from_child
after override - getLocal shared_key: from_child
findLocal child_key: child_value
getLocal child_key: child_value
end