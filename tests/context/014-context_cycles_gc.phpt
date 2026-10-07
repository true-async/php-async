--TEST--
Context values and object keys are visible to normal cycle collection
--FILE--
<?php

function abandonContext(bool $asKey): WeakReference
{
    $context = new Async\Context();

    if ($asKey) {
        $context->set($context, 'value');
    } else {
        $context->set('key', $context);
    }

    return WeakReference::create($context);
}

$valueCycle = abandonContext(false);
$keyCycle = abandonContext(true);

// Fill the GC root buffer and let the collector run on its normal schedule.
$threshold = gc_status()['threshold'];
for ($i = 0; $i < $threshold + 64; $i++) {
    abandonContext(($i & 1) !== 0);
}

var_dump($valueCycle->get() === null);
var_dump($keyCycle->get() === null);

$coroutineContext = null;
Async\await(Async\spawn(function () use (&$coroutineContext): void {
    $context = Async\coroutine_context();
    $coroutineContext = WeakReference::create($context);
    $context->set('self', $context);
}));

gc_collect_cycles();
Async\delay(1);
var_dump($coroutineContext->get() === null);

$scope = new Async\Scope();
$scopeContext = null;
Async\await($scope->spawn(function () use (&$scopeContext): void {
    $context = Async\current_context();
    $scopeContext = WeakReference::create($context);
    $context->set($context, 'self');
}));
$scope->dispose();
unset($scope);

gc_collect_cycles();
Async\delay(1);
var_dump($scopeContext->get() === null);

?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
