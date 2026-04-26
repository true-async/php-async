--TEST--
PDO_SQLite Pool: unset on the template destroys all slots and frees the registry
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
$pdo->createFunction('php_upper', fn(string $s) => strtoupper($s), 1);
$pdo->exec("CREATE TABLE t (val TEXT)");
$pdo->exec("INSERT INTO t (val) VALUES ('a'), ('b')");

// Use both pre-warmed slots so freeze + lazy apply both fire.
$tasks = [];
for ($i = 0; $i < 2; $i++) {
    $tasks[] = spawn(fn() => $pdo->query("SELECT php_upper(val) AS u FROM t LIMIT 1")->fetch()['u']);
}
foreach ($tasks as $t) { await($t); }

// Drop the template — pool destructor closes every slot, then closer
// frees the template registry. No leak should remain (run under valgrind
// to confirm cleanly, plain run just exercises the code path).
unset($pdo);
echo "freed\n";

AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
freed
Done
