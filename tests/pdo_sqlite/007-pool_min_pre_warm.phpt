--TEST--
PDO_SQLite Pool: POOL_MIN > 0 pre-warms slots before any acquire
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\spawn;
use function Async\await;

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp([
    PDO::ATTR_POOL_MIN => 2,
    PDO::ATTR_POOL_MAX => 2,
]);
$pdo->exec("CREATE TABLE t (val INT)");
$pdo->exec("INSERT INTO t (val) VALUES (10), (20)");

// Pre-warmed slots are valid: query through them via two coroutines.
$ra = spawn(fn() => $pdo->query("SELECT val FROM t WHERE val = 10")->fetch());
$rb = spawn(fn() => $pdo->query("SELECT val FROM t WHERE val = 20")->fetch());
echo await($ra)['val'], "\n";
echo await($rb)['val'], "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
10
20
Done
