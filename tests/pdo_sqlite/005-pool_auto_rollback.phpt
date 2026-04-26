--TEST--
PDO_SQLite Pool: uncommitted transaction is rolled back when slot returns to pool
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\spawn;
use function Async\await;

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp();
$pdo->exec("CREATE TABLE t (val INT)");

// Coroutine inserts inside a transaction and exits without commit.
// pdo_pool_before_release must rollback before the slot rejoins the pool.
await(spawn(function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t (val) VALUES (42)");
    // No commit / rollback — coroutine ends.
}));

$rows = await(spawn(function () use ($pdo) {
    return $pdo->query("SELECT val FROM t")->fetchAll();
}));
echo "rows=", count($rows), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
rows=0
Done
