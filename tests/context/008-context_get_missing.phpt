--TEST--
Context: get() returns null for missing keys
--FILE--
<?php

use Async\Context;
use function Async\current_context;

// Covers context.c METHOD(get) L242 RETURN_NULL fallback when
// async_context_find() reports the key as missing.

$ctx = current_context();
$ctx->set('present', 'value');

var_dump($ctx->get('present'));
var_dump($ctx->get('missing'));

?>
--EXPECT--
string(5) "value"
NULL
