--TEST--
PDO PgSQL Pool: errorInfo returns native details when connection is held
--EXTENSIONS--
pdo_pgsql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';
AsyncPDOPgSQLTest::skipIfNoAsync();
AsyncPDOPgSQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';

use function Async\spawn;
use function Async\await;

$pdo = AsyncPDOPgSQLTest::poolFactory(extra: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
]);

$coro = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 1 FROM nonexistent_table_xyz");

    $info = $pdo->errorInfo();
    echo "SQLSTATE: " . $info[0] . "\n";
    echo "Has native code: " . (isset($info[1]) && $info[1] !== null ? "yes" : "no") . "\n";
    echo "Has native message: " . (isset($info[2]) && $info[2] !== null ? "yes" : "no") . "\n";

    return true;
});

await($coro);
echo "Done\n";
?>
--EXPECT--
SQLSTATE: 42P01
Has native code: yes
Has native message: yes
Done
