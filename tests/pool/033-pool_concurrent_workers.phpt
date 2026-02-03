--TEST--
Pool: concurrent workers share limited resources
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

$results = [];
$maxConcurrent = 0;
$currentActive = 0;

$pool = new Pool(
    factory: function() {
        static $c = 0;
        return ++$c;
    },
    max: 2  // Only 2 resources for 5 workers
);

$workers = [];
for ($i = 1; $i <= 5; $i++) {
    $workerId = $i;
    $workers[] = spawn(function() use ($pool, $workerId, &$results, &$maxConcurrent, &$currentActive) {
        $r = $pool->acquire();

        $currentActive++;
        if ($currentActive > $maxConcurrent) {
            $maxConcurrent = $currentActive;
        }

        // Simulate work
        \Async\suspend();

        $results[] = "worker{$workerId}:resource{$r}";

        $currentActive--;
        $pool->release($r);
    });
}

// Wait for all workers
foreach ($workers as $w) {
    await($w);
}

echo "Workers completed: " . count($results) . "\n";
echo "Max concurrent: $maxConcurrent\n";
echo "Max allowed: 2\n";
echo "Concurrent within limit: " . ($maxConcurrent <= 2 ? "yes" : "no") . "\n";
echo "Pool idle: " . $pool->idleCount() . "\n";
echo "Pool total: " . $pool->count() . "\n";

$pool->close();
echo "Done\n";
?>
--EXPECT--
Workers completed: 5
Max concurrent: 2
Max allowed: 2
Concurrent within limit: yes
Pool idle: 2
Pool total: 2
Done
