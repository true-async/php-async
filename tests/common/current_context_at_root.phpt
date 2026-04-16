--TEST--
Async\current_context() and Async\coroutine_context() called at script root return fresh contexts
--FILE--
<?php

use function Async\current_context;
use function Async\coroutine_context;
use Async\Context;

// Covers async.c PHP_FUNCTION(Async_current_context) L717-720 (scope==NULL
// branch) and PHP_FUNCTION(Async_coroutine_context) L745-748 (coroutine==NULL
// branch) — both return a fresh independent Context when there is no current
// scope / coroutine.

$ctx1 = current_context();
var_dump($ctx1 instanceof Context);

$ctx2 = coroutine_context();
var_dump($ctx2 instanceof Context);

// Independent — setting on one does not leak to the other.
$ctx1->set('key', 'from-current');
var_dump($ctx2->has('key'));

?>
--EXPECT--
bool(true)
bool(true)
bool(false)
