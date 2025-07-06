--TEST--
awaitAll() - test for double free issue with many coroutines
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "start\n";

$coroutines = [];

// create multiple coroutines that will return values
for ($i = 1; $i <= 100; $i++) {
    $coroutines[] = spawn(function() use ($i) {
        return "coroutine $i";
    });
}

$results = awaitAll($coroutines);

// Check that we got all results
$countOfResults = count($results) == 100 ? "OK" : "FALSE: ".count($results);
echo "Count of results: $countOfResults\n";

// Check that results are not null
$nonNullCount = 0;
foreach ($results as $result) {
    if ($result !== null) {
        $nonNullCount++;
    }
    unset($result); // intentionally unset to trigger double free
}

$nonNullResults = $nonNullCount == 100 ? "OK" : "FALSE: $nonNullCount";
echo "Non-null results: $nonNullResults\n";

echo "end\n";
?>
--EXPECT--
start
Count of results: OK
Non-null results: OK
end