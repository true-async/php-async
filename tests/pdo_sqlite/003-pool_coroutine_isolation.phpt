--TEST--
PDO_SQLite Pool: each coroutine sees its own slot — concurrent transactions do not interfere
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
$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, owner TEXT, val INT)");

$a = spawn(function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t (owner, val) VALUES ('A', 1)");
    return $pdo->inTransaction() ? "A:in" : "A:out";
});
$b = spawn(function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t (owner, val) VALUES ('B', 2)");
    $pdo->commit();
    return $pdo->inTransaction() ? "B:in" : "B:out";
});

echo await($a), "\n";  // A still in txn — slot stays bound until coroutine ends
echo await($b), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
A:in
B:out
Done
