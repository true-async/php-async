--TEST--
PDO_SQLite Pool: beginTransaction / commit / rollback inside a coroutine
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

await(spawn(function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t (val) VALUES (1)");
    $pdo->commit();

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t (val) VALUES (2)");
    $pdo->rollBack();
}));

$rows = await(spawn(function () use ($pdo) {
    return $pdo->query("SELECT val FROM t ORDER BY val")->fetchAll();
}));
echo implode(',', array_column($rows, 'val')), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
1
Done
