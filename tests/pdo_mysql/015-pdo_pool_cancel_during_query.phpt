--TEST--
PDO MySQL Pool: cancel coroutine during active query, pool survives without crash
--EXTENSIONS--
pdo_mysql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';
AsyncPDOMySQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';

use function Async\spawn;
use function Async\await;
use Async\AsyncCancellation;

/*
 * Scenario:
 * 1. Coroutine A starts a slow query (SLEEP(5)) on a pooled connection
 * 2. Coroutine A gets cancelled while the query is in-flight
 * 3. The cancelled connection goes back to pool (broken — MySQL gone away)
 * 4. Coroutine B gets a DIFFERENT connection (pool max=2) and works fine
 *
 * Key: no segfault, no crash, pool stays functional.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 2,
]);

$pool = $pdo->getPool();
echo "Pool count before: " . $pool->count() . "\n";

// Coroutine A: slow query that will be cancelled
$coroA = spawn(function() use ($pdo) {
    try {
        $stmt = $pdo->query("SELECT SLEEP(5) as s");
        echo "Coro A: query completed (unexpected)\n";
    } catch (AsyncCancellation $e) {
        echo "Coro A: cancelled\n";
    }
});

// Coroutine B: runs concurrently, gets its own connection from pool
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 42 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: val=" . $row['val'] . "\n";
});

// Give both coroutines time to start
usleep(100_000);

// Cancel coroutine A while B is already done or running
$coroA->cancel(new AsyncCancellation("test cancel"));

try {
    await($coroA);
} catch (AsyncCancellation $e) {
    echo "await A: cancelled\n";
}

await($coroB);

echo "Pool count after: " . $pool->count() . "\n";
echo "No crash\n";
?>
--EXPECTF--
Pool count before: 0
Coro B: val=42
Coro A: cancelled
Pool count after: %d
No crash
