--TEST--
PDO PgSQL Pool: Uncommitted transaction is rolled back when connection returns to pool
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

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 2);

$pdo->exec("DROP TABLE IF EXISTS test_pool_pgsql_auto_rb");
$pdo->exec("CREATE TABLE test_pool_pgsql_auto_rb (id INT PRIMARY KEY, value VARCHAR(50))");
$pdo->exec("INSERT INTO test_pool_pgsql_auto_rb VALUES (1, 'initial')");

echo "Testing auto-rollback on coroutine end\n";

$coro = spawn(function() use ($pdo) {
    echo "Coro: starting transaction\n";
    $pdo->beginTransaction();
    $pdo->exec("UPDATE test_pool_pgsql_auto_rb SET value = 'modified' WHERE id = 1");
    echo "Coro: updated but NOT committing\n";
    return "coro_done";
});

await($coro);
echo "Coroutine finished\n";

$stmt = $pdo->query("SELECT value FROM test_pool_pgsql_auto_rb WHERE id = 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Value after coroutine: " . $row['value'] . "\n";
if ($row['value'] === 'initial') {
    echo "Auto-rollback: OK\n";
} else {
    echo "Auto-rollback: FAILED (expected 'initial')\n";
}

$pdo->exec("DROP TABLE IF EXISTS test_pool_pgsql_auto_rb");

echo "Done\n";
?>
--EXPECT--
Testing auto-rollback on coroutine end
Coro: starting transaction
Coro: updated but NOT committing
Coroutine finished
Value after coroutine: initial
Auto-rollback: OK
Done
