--TEST--
include with echo output inside coroutine
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    echo "before_include\n";
    include __DIR__ . '/test_include_with_output.inc';
    echo "after_include\n";
});

await($c1);

echo "done\n";
?>
--EXPECT--
before_include
output_from_include
after_include
done
