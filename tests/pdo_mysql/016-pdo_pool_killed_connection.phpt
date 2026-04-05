--TEST--
PDO MySQL Pool: killed connection is destroyed, next coroutine gets fresh one
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

/*
 * Scenario: database kills our connection (DBA runs KILL, server restart, network drop).
 * Pool must detect conn_broken and destroy instead of reusing.
 *
 * Pool max=1 forces coroutine B to reuse the same slot.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 1,
]);

// Separate non-pooled connection for KILL
$killer = AsyncPDOMySQLTest::factory();
$pool = $pdo->getPool();

// Coroutine A: get connection, kill it externally, try to use it
$coroA = spawn(function() use ($pdo, $killer) {
    $stmt = $pdo->query("SELECT CONNECTION_ID() as id");
    $id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    echo "Coro A: conn_id=$id\n";
    $stmt = null;

    // Kill our pooled connection from outside
    $killer->exec("KILL $id");

    // Small delay to let MySQL process the KILL
    usleep(50_000);

    // Next query should fail — connection is dead
    $result = $pdo->exec("SELECT 1");
    echo "Coro A: errorCode=" . $pdo->errorCode() . "\n";
});

await($coroA);

echo "Pool count after A: " . $pool->count() . "\n";

// Coroutine B: pool should create fresh connection (broken one destroyed)
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 42 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: val=" . $row['val'] . "\n";
});

await($coroB);
echo "Done\n";
?>
--EXPECTF--
Coro A: conn_id=%d
Coro A: errorCode=HY000
Pool count after A: 0
Coro B: val=42
Done
