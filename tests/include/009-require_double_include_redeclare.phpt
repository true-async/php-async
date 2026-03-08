--TEST--
require same file twice in coroutine - must trigger redeclare error
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// require (not _once) the same file twice — must produce fatal error
$c1 = spawn(function() {
    require __DIR__ . '/test_include_file.inc';
    echo "first require ok\n";

    try {
        require __DIR__ . '/test_include_file.inc';
        echo "BUG: second require did not fail\n";
    } catch (\Error $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
});

await($c1);

echo "done\n";
?>
--EXPECTF--
first require ok

Fatal error: Cannot redeclare function test_included_function() (previously declared in %stest_include_file.inc:%d) in %stest_include_file.inc on line %d
