--TEST--
PDO_SQLite Pool: ATTR_POOL_ENABLED creates a pool template and basic query works
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
echo "created\n";

$row = await(spawn(function () use ($pdo) {
    return $pdo->query("SELECT 1 AS n")->fetch();
}));
echo "n=", $row['n'], "\n";

unset($pdo);
AsyncPDOSqliteTest::cleanup($path);
echo "Done\n";
?>
--EXPECT--
created
n=1
Done
