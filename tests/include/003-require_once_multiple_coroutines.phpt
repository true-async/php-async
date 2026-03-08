--TEST--
require_once from multiple coroutines - no redeclare error
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// First coroutine does require (not _once)
$c1 = spawn(function() {
    require __DIR__ . '/test_include_file.inc';
    echo "c1: " . test_included_function() . "\n";
});

await($c1);

// Second coroutine does require_once of the same file — must not redeclare
$c2 = spawn(function() {
    require_once __DIR__ . '/test_include_file.inc';
    echo "c2: " . test_included_function() . "\n";
});

await($c2);

// Third: require_once from main — also must not redeclare
require_once __DIR__ . '/test_include_file.inc';
echo "main: " . test_included_function() . "\n";

echo "done\n";
?>
--EXPECT--
c1: included_ok
c2: included_ok
main: included_ok
done
