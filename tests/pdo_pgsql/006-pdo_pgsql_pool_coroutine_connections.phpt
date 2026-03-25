--TEST--
PDO PgSQL Pool: Each coroutine gets its own connection from pool
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
echo "Pool created\n";
echo "Initial pool count: " . $pool->count() . "\n";

$coro1 = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT pg_backend_pid() as pid");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)$row['pid'];
});

$coro2 = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT pg_backend_pid() as pid");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)$row['pid'];
});

$pid1 = await($coro1);
$pid2 = await($coro2);

echo "Connection 1 PID: " . ($pid1 > 0 ? "valid" : "invalid") . "\n";
echo "Connection 2 PID: " . ($pid2 > 0 ? "valid" : "invalid") . "\n";
echo "Connections are different: " . ($pid1 !== $pid2 ? "yes" : "no") . "\n";
echo "Pool count after coroutines: " . $pool->count() . "\n";

echo "Done\n";
?>
--EXPECT--
Pool created
Initial pool count: 0
Connection 1 PID: valid
Connection 2 PID: valid
Connections are different: yes
Pool count after coroutines: 2
Done
