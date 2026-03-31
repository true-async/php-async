--TEST--
PDO PgSQL Pool: Connection released to pool only after all statements destroyed
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

$pdo = AsyncPDOPgSQLTest::poolFactory();
$pool = $pdo->getPool();

echo "Initial idle: " . $pool->idleCount() . "\n";

$coro = spawn(function() use ($pdo, $pool) {
    $stmt1 = $pdo->query("SELECT 1");
    $stmt2 = $pdo->query("SELECT 2");

    echo "With 2 stmts, idle: " . $pool->idleCount() . "\n";

    unset($stmt1);
    echo "After unset(stmt1), idle: " . $pool->idleCount() . "\n";

    unset($stmt2);
    echo "After unset(stmt2), idle: " . $pool->idleCount() . "\n";

    return true;
});

await($coro);
echo "After coroutine, idle: " . $pool->idleCount() . "\n";
echo "Done\n";
?>
--EXPECT--
Initial idle: 0
With 2 stmts, idle: 0
After unset(stmt1), idle: 0
After unset(stmt2), idle: 1
After coroutine, idle: 1
Done
