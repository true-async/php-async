--TEST--
PDO PgSQL Pool: connection terminated during transaction, pool recovers
--EXTENSIONS--
pdo_pgsql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';
AsyncPDOPgSQLTest::skipIfNoAsync();
AsyncPDOPgSQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';

use function Async\spawn;
use function Async\await;

/*
 * Connection terminated while transaction is active.
 * Broken connection must not be returned to pool.
 * Next coroutine gets fresh connection and can work normally.
 */

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
]);

$killer = AsyncPDOPgSQLTest::factory();
$pool = $pdo->getPool();

// Setup
$pdo->exec("DROP TABLE IF EXISTS test_kill_txn");
$pdo->exec("CREATE TABLE test_kill_txn (id INT PRIMARY KEY, val TEXT)");

// Coroutine A: start transaction, get killed
$coroA = spawn(function() use ($pdo, $killer) {
    $stmt = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid = $stmt->fetch(PDO::FETCH_ASSOC)['pid'];
    $stmt = null;

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO test_kill_txn VALUES (1, 'should_be_lost')");

    // Kill connection mid-transaction
    $killer->exec("SELECT pg_terminate_backend($pid)");
    usleep(50_000);

    // This should fail
    $pdo->exec("INSERT INTO test_kill_txn VALUES (2, 'also_lost')");
    echo "Coro A: failed=" . ($pdo->errorCode() !== '00000' ? 'yes' : 'no') . "\n";
});

await($coroA);

echo "Pool count: " . $pool->count() . "\n";

// Coroutine B: fresh connection, verify transaction was lost
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM test_kill_txn");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: rows=" . $row['cnt'] . "\n";

    // Can start own transaction
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO test_kill_txn VALUES (10, 'fresh')");
    $pdo->commit();

    $stmt = $pdo->query("SELECT val FROM test_kill_txn WHERE id=10");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: val=" . $row['val'] . "\n";
});

await($coroB);

$pdo->exec("DROP TABLE IF EXISTS test_kill_txn");
echo "Done\n";
?>
--EXPECT--
Coro A: failed=yes
Pool count: 0
Coro B: rows=0
Coro B: val=fresh
Done
