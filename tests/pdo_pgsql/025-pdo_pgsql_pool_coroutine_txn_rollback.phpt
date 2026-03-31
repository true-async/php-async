--TEST--
PDO PgSQL Pool: Uncommitted transaction rolled back when coroutine finishes
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

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 3);

// Setup
$pdo->exec("DROP TABLE IF EXISTS test_txn_rollback");
$pdo->exec("CREATE TABLE test_txn_rollback (id SERIAL PRIMARY KEY, val TEXT)");

// Coroutine starts transaction but never commits — should be rolled back
$coro = spawn(function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO test_txn_rollback (val) VALUES ('should_be_rolled_back')");
    echo "Inserted in transaction\n";
    // Coroutine ends without commit — pool's before_release should rollback
});

await($coro);

// Check that the row was NOT persisted
$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM test_txn_rollback");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Rows after rollback: " . $row['cnt'] . "\n";

// Cleanup
$pdo->exec("DROP TABLE test_txn_rollback");
echo "Done\n";
?>
--EXPECT--
Inserted in transaction
Rows after rollback: 0
Done
