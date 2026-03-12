--TEST--
require in coroutine then require (not _once) in main - must trigger redeclare error
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    require __DIR__ . '/test_include_file.inc';
    echo "coroutine: " . test_included_function() . "\n";
});

await($c1);

// now require again from main (not _once) — must fail
require __DIR__ . '/test_include_file.inc';
echo "BUG: should not reach here\n";
?>
--EXPECTF--
coroutine: included_ok

Fatal error: Cannot redeclare function test_included_function() (previously declared in %stest_include_file.inc:%d) in %stest_include_file.inc on line %d
