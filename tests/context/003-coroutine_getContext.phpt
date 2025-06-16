--TEST--
Coroutine getContext method
--EXTENSION--
async
--FILE--
<?php

use Async\{spawn, suspend, Context, Coroutine};

// Test coroutine with context
$coroutine = spawn(function() {
    $context = new Context();
    $context->set('test_key', 'test_value');
    
    // Get context from coroutine
    $currentCoroutine = Coroutine::getCurrent();
    $contextFromCoroutine = $currentCoroutine->getContext();
    
    if ($contextFromCoroutine !== null) {
        var_dump($contextFromCoroutine instanceof Context);
        var_dump($contextFromCoroutine->has('test_key'));
    } else {
        echo "Context is null\n";
    }
    
    return 'done';
});

suspend();

// Test getting context from finished coroutine
$context = $coroutine->getContext();
if ($context !== null) {
    var_dump($context instanceof Context);
} else {
    echo "Finished coroutine context is null\n";
}

// Test coroutine without context
$coroutineNoContext = spawn(function() {
    return 'no context';
});

suspend();

$noContext = $coroutineNoContext->getContext();
if ($noContext === null) {
    echo "No context coroutine returns null\n";
} else {
    echo "Unexpected context found\n";
}

?>
--EXPECT--
Context is null
Finished coroutine context is null
No context coroutine returns null