--TEST--
Coroutine getContext method
--FILE--
<?php

use Async\{Context, Coroutine};

// Test coroutine with context
$coroutine = Async\spawn(function() {
    $context = Async\coroutine_context();
    $context->set('test_key', 'test_value');
    
    // Get context from coroutine
    $currentCoroutine = Async\current_coroutine();
    $contextFromCoroutine = $currentCoroutine->getContext();
    
    if ($contextFromCoroutine !== null) {
        var_dump($contextFromCoroutine instanceof Context);
        var_dump($contextFromCoroutine->has('test_key'));
    } else {
        echo "Context is null\n";
    }
    
    return 'done';
});

Async\suspend();

// Test getting context from finished coroutine
$context = $coroutine->getContext();
if ($context !== null) {
    var_dump($context instanceof Context);
} else {
    echo "Finished coroutine context is null\n";
}

// Test coroutine without context
$coroutineNoContext = Async\spawn(function() {
    return 'no context';
});

Async\suspend();

$noContext = $coroutineNoContext->getContext();
if ($noContext === null) {
    echo "No context coroutine returns null\n";
} else {
    echo "context found\n";
}

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
context found