--TEST--
require inside coroutine - visibility in main and other coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

// require in coroutine
$c1 = spawn(function() {
    require __DIR__ . '/test_include_file.inc';

    echo "c1: " . test_included_function() . "\n";
    echo "c1: " . (new TestIncludedClass())->getValue() . "\n";
    echo "c1: " . TEST_INCLUDED_CONST . "\n";
});

await($c1);

// check visibility from main scope after coroutine finished
echo "main: " . test_included_function() . "\n";
echo "main: " . (new TestIncludedClass())->getValue() . "\n";
echo "main: " . TEST_INCLUDED_CONST . "\n";

// check visibility from another coroutine
$c2 = spawn(function() {
    echo "c2: " . test_included_function() . "\n";
    echo "c2: " . (new TestIncludedClass())->getValue() . "\n";
    echo "c2: " . TEST_INCLUDED_CONST . "\n";
});

await($c2);

echo "done\n";
?>
--EXPECT--
c1: included_ok
c1: class_ok
c1: 42
main: included_ok
main: class_ok
main: 42
c2: included_ok
c2: class_ok
c2: 42
done
