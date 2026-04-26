--TEST--
PDO_SQLite Pool: createAggregate works across slots
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

$pdo->createAggregate('php_sum',
    fn(?int $acc, int $rownum, int $val): int => ($acc ?? 0) + $val,
    fn(?int $acc, int $rownum): int => $acc ?? 0,
    1
);

$pdo->exec("CREATE TABLE t (n INT)");
$pdo->exec("INSERT INTO t (n) VALUES (1), (2), (3), (4), (5)");

$row = await(spawn(function () use ($pdo) {
    return $pdo->query("SELECT php_sum(n) AS s FROM t")->fetch();
}));
echo "sum=", $row['s'], "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
sum=15
Done
