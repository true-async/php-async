--TEST--
Pool: after the healthcheck destroys a resource, a failing factory fails the acquire, not the scheduler
--FILE--
<?php

use Async\Pool;
use function Async\spawn;
use function Async\await;
use function Async\delay;

$created = 0;
$down = false;

$pool = new Pool(
    factory: function () use (&$created, &$down) {
        if ($down) {
            throw new RuntimeException("backend down");
        }
        return ++$created;
    },
    healthcheck: function ($r) use (&$down) {
        $down = true; // the backend went away together with the connection
        return false;
    },
    min: 1,
    max: 1,
    healthcheckInterval: 20,
);

$other = spawn(function () {
    delay(80);
    return "unrelated coroutine finished";
});

delay(60);
echo "after the healthcheck: count ", $pool->count(), "\n";
echo await($other), "\n";

try {
    $pool->acquire();
} catch (RuntimeException $e) {
    echo "acquire: ", $e->getMessage(), "\n";
}

$pool->close();
echo "done\n";
?>
--EXPECT--
after the healthcheck: count 0
unrelated coroutine finished
acquire: backend down
done
