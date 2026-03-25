--TEST--
PDO PgSQL Pool: Refcount and transaction pinning work together
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
$pool = $pdo->getPool();

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_txn_ref");
$pdo->exec("CREATE TABLE test_pgsql_pool_txn_ref (id SERIAL PRIMARY KEY, val VARCHAR(50))");

$coro = spawn(function() use ($pdo, $pool) {
    $pdo->beginTransaction();

    $stmt1 = $pdo->prepare("INSERT INTO test_pgsql_pool_txn_ref (val) VALUES (?)");
    $stmt1->execute(['row1']);

    $stmt2 = $pdo->prepare("INSERT INTO test_pgsql_pool_txn_ref (val) VALUES (?)");
    $stmt2->execute(['row2']);

    unset($stmt1);
    unset($stmt2);
    echo "After unset both stmts, idle: " . $pool->idleCount() . "\n";

    $pdo->exec("INSERT INTO test_pgsql_pool_txn_ref (val) VALUES ('row3')");
    $pdo->commit();
    echo "Committed\n";

    echo "After commit, idle: " . $pool->idleCount() . "\n";

    return true;
});

await($coro);

$stmt = $pdo->query("SELECT val FROM test_pgsql_pool_txn_ref ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
echo "Rows: " . implode(', ', $rows) . "\n";

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_txn_ref");
echo "Done\n";
?>
--EXPECT--
After unset both stmts, idle: 0
Committed
After commit, idle: 1
Rows: row1, row2, row3
Done
