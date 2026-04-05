--TEST--
PDO MySQL Pool: cancel coroutine during transaction, connection destroyed
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

use Async\Channel;
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;

/*
 * Coroutine starts transaction, inserts data, then gets cancelled during slow query.
 * Broken connection destroyed. Next coroutine verifies: data not committed, pool works.
 * Channel used as synchronization — no timing dependency.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 1,
]);

$pool = $pdo->getPool();
$ready = new Channel(1);

// Setup
$pdo->exec("CREATE TABLE IF NOT EXISTS test_cancel_txn (id INT PRIMARY KEY, val TEXT)");
$pdo->exec("TRUNCATE TABLE test_cancel_txn");

$coroA = spawn(function() use ($pdo, $ready) {
    try {
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO test_cancel_txn VALUES (1, 'uncommitted')");

        $ready->send(true);
        $pdo->query("SELECT SLEEP(5)");
    } catch (AsyncCancellation $e) {
        // cancelled during SLEEP
    }
});

$ready->recv();
$coroA->cancel(new AsyncCancellation("cancel txn"));

try { await($coroA); } catch (AsyncCancellation $e) {}

echo "Pool count: " . $pool->count() . "\n";

// Coroutine B: verify no data leaked, fresh connection works
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
