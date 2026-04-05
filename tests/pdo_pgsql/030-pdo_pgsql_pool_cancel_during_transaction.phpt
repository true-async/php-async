--TEST--
PDO PgSQL Pool: cancel coroutine during transaction, connection destroyed
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

use Async\Channel;
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 1);
$pool = $pdo->getPool();
$ready = new Channel(1);

// Setup
$pdo->exec("DROP TABLE IF EXISTS test_cancel_txn");
$pdo->exec("CREATE TABLE test_cancel_txn (id INT PRIMARY KEY, val TEXT)");

$coroA = spawn(function() use ($pdo, $ready) {
    try {
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO test_cancel_txn VALUES (1, 'uncommitted')");

        $ready->send(true);
        $pdo->query("SELECT pg_sleep(5)");
    } catch (AsyncCancellation $e) {
        // cancelled during pg_sleep
    }
});

$ready->recv();
$coroA->cancel(new AsyncCancellation("cancel txn"));

try { await($coroA); } catch (AsyncCancellation $e) {}

echo "Pool count: " . $pool->count() . "\n";

$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM test_cancel_txn");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Rows: " . $row['cnt'] . "\n";

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO test_cancel_txn VALUES (10, 'committed')");
    $pdo->commit();

    $stmt = $pdo->query("SELECT val FROM test_cancel_txn WHERE id=10");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Val: " . $row['val'] . "\n";
});

await($coroB);

$pdo->exec("DROP TABLE IF EXISTS test_cancel_txn");
echo "Done\n";
?>
--EXPECT--
Pool count: 0
Rows: 0
Val: committed
Done
