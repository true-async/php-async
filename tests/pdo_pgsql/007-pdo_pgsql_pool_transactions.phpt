--TEST--
PDO PgSQL Pool: Transaction state tracked per pooled connection
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

$pdo = AsyncPDOPgSQLTest::poolFactory();

$pdo->exec("DROP TABLE IF EXISTS test_pool_pgsql_txn");
$pdo->exec("CREATE TABLE test_pool_pgsql_txn (id INT PRIMARY KEY, value VARCHAR(50))");

echo "Testing transactions in coroutines\n";

$coro1 = spawn(function() use ($pdo) {
    echo "Coro1: starting transaction\n";
    $pdo->beginTransaction();
    echo "Coro1: inTransaction = " . ($pdo->inTransaction() ? "yes" : "no") . "\n";
    $pdo->exec("INSERT INTO test_pool_pgsql_txn VALUES (1, 'from_coro1')");
    $pdo->commit();
    echo "Coro1: committed\n";
    return "coro1_done";
});

await($coro1);

$coro2 = spawn(function() use ($pdo) {
    echo "Coro2: starting transaction\n";
    $pdo->beginTransaction();
    echo "Coro2: inTransaction = " . ($pdo->inTransaction() ? "yes" : "no") . "\n";
    $pdo->exec("INSERT INTO test_pool_pgsql_txn VALUES (2, 'from_coro2')");
    $pdo->rollBack();
    echo "Coro2: rolled back\n";
    return "coro2_done";
});

await($coro2);

$stmt = $pdo->query("SELECT * FROM test_pool_pgsql_txn ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Rows in table: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo "  id={$row['id']}, value={$row['value']}\n";
}

$pdo->exec("DROP TABLE IF EXISTS test_pool_pgsql_txn");

echo "Done\n";
?>
--EXPECTF--
Testing transactions in coroutines
Coro1: starting transaction
Coro1: inTransaction = yes
Coro1: committed
Coro2: starting transaction
Coro2: inTransaction = yes
Coro2: rolled back
Rows in table: 1
  id=1, value=from_coro1
Done
