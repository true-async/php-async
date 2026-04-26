--TEST--
PDO_SQLite Pool: file:...?mode=memory&cache=shared is allowed (slots see same DB)
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\spawn;
use function Async\await;

$dsn = AsyncPDOSqliteTest::sharedMemoryDsn('async_pool_shared_' . uniqid());
$pdo = Pdo\Sqlite::connect($dsn, null, null, AsyncPDOSqliteTest::poolOptions());
$pdo->exec("CREATE TABLE t (val INT)");
$pdo->exec("INSERT INTO t (val) VALUES (1), (2)");

$rows = await(spawn(function () use ($pdo) {
    return $pdo->query("SELECT val FROM t ORDER BY val")->fetchAll();
}));
echo implode(',', array_column($rows, 'val')), "\n";

unset($pdo);
echo "Done\n";
?>
--EXPECT--
1,2
Done
