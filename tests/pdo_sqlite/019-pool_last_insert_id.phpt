--TEST--
PDO_SQLite Pool: lastInsertId is per-coroutine (each slot tracks its own last rowid)
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\spawn;
use function Async\await;

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp([PDO::ATTR_POOL_MAX => 2]);
$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, val INT)");

// Two coroutines insert rows; each must see its own lastInsertId.
$a = spawn(function () use ($pdo) {
    $pdo->beginTransaction();          // pin a slot for the whole txn
    $pdo->exec("INSERT INTO t (val) VALUES (10)");
    $id = $pdo->lastInsertId();
    $pdo->commit();
    return $id;
});
$b = spawn(function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t (val) VALUES (20)");
    $id = $pdo->lastInsertId();
    $pdo->commit();
    return $id;
});

$ids = [(int) await($a), (int) await($b)];
sort($ids);
echo "ids=", implode(',', $ids), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
ids=1,2
Done
