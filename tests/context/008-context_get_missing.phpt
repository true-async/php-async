--TEST--
Context: get() throws for missing keys
--FILE--
<?php

use function Async\current_context;

// get() is the mandatory-value form: a missing key is an error, not a null.
// find() is the one that answers null.

$ctx = current_context();
$ctx->set('present', 'value');

var_dump($ctx->get('present'));

try {
    $ctx->get('missing');
    echo "NOT THROWN\n";
} catch (Async\ContextException $exception) {
    echo $exception->getMessage(), "\n";
}

var_dump($ctx->find('missing'));

?>
--EXPECT--
string(5) "value"
Context key "missing" not found
NULL
