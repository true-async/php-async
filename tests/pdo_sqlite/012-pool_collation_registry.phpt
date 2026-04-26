--TEST--
PDO_SQLite Pool: createCollation is honoured by every slot's ORDER BY
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

$pdo->createCollation('reverse',
    fn(string $a, string $b) => strcmp(strrev($a), strrev($b))
);

$pdo->exec("CREATE TABLE t (val TEXT)");
$pdo->exec("INSERT INTO t (val) VALUES ('alpha'), ('bravo'), ('charlie')");

$rows = await(spawn(function () use ($pdo) {
    return $pdo->query("SELECT val FROM t ORDER BY val COLLATE reverse")->fetchAll();
}));
echo implode(',', array_column($rows, 'val')), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
alpha,charlie,bravo
Done
