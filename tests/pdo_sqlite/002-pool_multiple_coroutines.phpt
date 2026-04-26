--TEST--
PDO_SQLite Pool: N coroutines run queries on the same template handle
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
$pdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY, val TEXT)");
$pdo->exec("INSERT INTO t (val) VALUES ('a'), ('b'), ('c'), ('d'), ('e')");

$tasks = [];
for ($i = 0; $i < 5; $i++) {
    $tasks[] = spawn(function () use ($pdo, $i) {
        $row = $pdo->query("SELECT val FROM t WHERE id = " . ($i + 1))->fetch();
        return "$i:" . $row['val'];
    });
}
$results = [];
foreach ($tasks as $t) { $results[] = await($t); }
sort($results);
echo implode(',', $results), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
0:a,1:b,2:c,3:d,4:e
Done
