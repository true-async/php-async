--TEST--
PDO MySQL Pool: connection killed during transaction, pool recovers
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
 * Scenario: connection is killed while a transaction is active.
 * The broken connection must not be returned to pool.
 * Next coroutine gets a fresh connection and can start its own transaction.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 1,
]);

$killer = AsyncPDOMySQLTest::factory();
$pool = $pdo->getPool();

// Setup
$pdo->exec("CREATE TABLE IF NOT EXISTS test_kill_txn (id INT PRIMARY KEY, val TEXT)");
$pdo->exec("TRUNCATE TABLE test_kill_txn");

// Coroutine A: start transaction, get killed
$coroA = spawn(function() use ($pdo, $killer) {
    $stmt = $pdo->query("SELECT CONNECTION_ID() as id");
    $connId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    $stmt = null;

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO test_kill_txn VALUES (1, 'should_be_lost')");

    // Kill connection mid-transaction
    $killer->exec("KILL $connId");
    usleep(50_000);

    // This should fail
    $result = $pdo->exec("INSERT INTO test_kill_txn VALUES (2, 'also_lost')");
    echo "Coro A: errorCode=" . $pdo->errorCode() . "\n";
});

await($coroA);

echo "Pool count: " . $pool->count() . "\n";

// Coroutine B: fresh connection, verify transaction was lost
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM test_kill_txn");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: rows=" . $row['cnt'] . "\n";

    // Can start own transaction on fresh connection
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO test_kill_txn VALUES (10, 'fresh')");
    $pdo->commit();

    $stmt = $pdo->query("SELECT val FROM test_kill_txn WHERE id=10");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: val=" . $row['val'] . "\n";
});

await($coroB);

// Cleanup
$pdo->exec("DROP TABLE IF EXISTS test_kill_txn");
echo "Done\n";
?>
--EXPECT--
Coro A: errorCode=HY000
Pool count: 0
Coro B: rows=0
Coro B: val=fresh
Done
