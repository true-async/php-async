--TEST--
Pool: producer-consumer pattern with resource pool
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;

// Simulate connection pool
$pool = new Pool(
    factory: function() {
        static $c = 0;
        return "conn-" . ++$c;
    },
    max: 3
);

$processed = 0;

// Process 10 tasks with max 3 connections
$tasks = [];
for ($i = 1; $i <= 10; $i++) {
    $taskId = $i;
    $tasks[] = spawn(function() use ($pool, $taskId, &$processed) {
        $conn = $pool->acquire();
        // Simulate work
        \Async\suspend();
        $processed++;
        $pool->release($conn);
    });
}

foreach ($tasks as $t) {
    await($t);
}

echo "Tasks processed: $processed\n";
echo "Resources created: " . $pool->count() . "\n";
echo "Max was 3: " . ($pool->count() <= 3 ? "yes" : "no") . "\n";
echo "All idle now: " . ($pool->idleCount() === $pool->count() ? "yes" : "no") . "\n";

$pool->close();
echo "Done\n";
?>
--EXPECT--
Tasks processed: 10
Resources created: 3
Max was 3: yes
All idle now: yes
Done
