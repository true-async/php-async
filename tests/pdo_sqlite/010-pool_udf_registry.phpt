--TEST--
PDO_SQLite Pool: createFunction registers UDF visible to every slot
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
$pdo->createFunction('php_upper', fn(string $s) => strtoupper($s), 1);

$pdo->exec("CREATE TABLE t (val TEXT)");
$pdo->exec("INSERT INTO t (val) VALUES ('alpha'), ('bravo')");

$tasks = [];
for ($i = 0; $i < 4; $i++) {
    $tasks[] = spawn(function () use ($pdo) {
        return $pdo->query("SELECT php_upper(val) AS u FROM t ORDER BY val LIMIT 1")->fetch()['u'];
    });
}
$results = [];
foreach ($tasks as $t) { $results[] = await($t); }
echo implode(',', $results), "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
ALPHA,ALPHA,ALPHA,ALPHA
Done
