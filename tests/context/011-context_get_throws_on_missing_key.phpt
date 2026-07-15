--TEST--
Context: get()/getLocal() throw on a missing key, find()/findLocal() answer null
--FILE--
<?php

use function Async\current_context;

// get() used to be an exact duplicate of find(): both walked the parents and both answered
// null. It is the mandatory-value form -- a missing key is an error, not a null.
$context = current_context();
$context->set('present', 'V');

echo "find(missing):      ", var_export($context->find('missing'), true), "\n";
echo "findLocal(missing): ", var_export($context->findLocal('missing'), true), "\n";

try {
    $context->get('missing');
    echo "get(missing): NOT THROWN\n";
} catch (Async\ContextException $exception) {
    echo "get(missing):      ", $exception->getMessage(), "\n";
}

try {
    $context->getLocal('missing');
    echo "getLocal(missing): NOT THROWN\n";
} catch (Async\ContextException $exception) {
    echo "getLocal(missing): ", $exception->getMessage(), "\n";
}

echo "get(present):       ", var_export($context->get('present'), true), "\n";
echo "extends AsyncException: ", var_export(is_subclass_of(Async\ContextException::class, Async\AsyncException::class), true), "\n";
?>
--EXPECT--
find(missing):      NULL
findLocal(missing): NULL
get(missing):      Context key "missing" not found
getLocal(missing): Context key "missing" not found
get(present):       'V'
extends AsyncException: true
