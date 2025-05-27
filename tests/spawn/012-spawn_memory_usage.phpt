--TEST--
Future: spawn() - memory usage with coroutines
--FILE--
<?php

use function Async\spawn;

echo "start\n";

$initial_memory = memory_get_usage();

// Create many coroutines to test memory management
for ($i = 0; $i < 50; $i++) {
    spawn(function() use ($i) {
        $data = array_fill(0, 100, "data_$i");
        unset($data);
    });
}

$after_spawn_memory = memory_get_usage();

echo "Memory increased: " . (($after_spawn_memory - $initial_memory) > 0 ? "yes" : "no") . "\n";
echo "end\n";
?>
--EXPECT--
start
Memory increased: yes
end