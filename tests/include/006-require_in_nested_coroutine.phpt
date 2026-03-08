--TEST--
require inside nested coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\await;

$c1 = spawn(function() {
    require_once __DIR__ . '/test_include_file.inc';
    echo "outer: " . test_included_function() . "\n";

    $inner = spawn(function() {
        require_once __DIR__ . '/test_include_file.inc';
        echo "inner: " . test_included_function() . "\n";

        $result = include __DIR__ . '/test_include_returns.inc';
        echo "inner data: " . $result['number'] . "\n";
    });

    await($inner);
    echo "outer end\n";
});

await($c1);

echo "done\n";
?>
--EXPECT--
outer: included_ok
inner: included_ok
inner data: 123
outer end
done
