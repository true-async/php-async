--TEST--
Scope: GC handler with context data
--FILE--
<?php

use Async\Scope;
use Async\Context;
use function Async\spawn;

// Test for a special case:
// If a Scope is created with a faulty constructor,
// the dtor method will not be called. However, even in this case, the
// memory must be properly freed.
$scope = new Scope(2);

?>
--EXPECTF--
Fatal error: Uncaught ArgumentCountError: %s
%a