--TEST--
require different files from concurrent coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    $result = include __DIR__ . '/test_include_returns.inc';
    echo "c1: " . $result['key'] . "\n";
});

$c2 = spawn(function() {
    include __DIR__ . '/test_include_with_output.inc';
    echo "c2: done\n";
});

await($c1);
await($c2);

echo "done\n";
?>
--EXPECT--
c1: value
output_from_include
c2: done
done
