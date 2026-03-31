--TEST--
PDO PgSQL Pool: Idle buffer grows when max_size exceeds initial capacity
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
use function Async\await_all_or_fail;

// max=12 exceeds default idle buffer — tests dynamic resize
$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 12);
$pool = $pdo->getPool();

// Spawn 12 coroutines — each will acquire a connection
$coroutines = [];
for ($i = 0; $i < 12; $i++) {
    $coroutines[] = spawn(function () use ($pdo) {
        $stmt = $pdo->query("SELECT 1");
        $stmt->fetch();
    });
}

await_all_or_fail($coroutines);

// All 12 connections should be back in idle
echo "Pool count: " . $pool->count() . "\n";
echo "Idle count: " . $pool->idleCount() . "\n";
echo "Active count: " . $pool->activeCount() . "\n";
echo "All idle: " . ($pool->idleCount() === $pool->count() ? "yes" : "no") . "\n";
echo "Done\n";
?>
--EXPECT--
Pool count: 12
Idle count: 12
Active count: 0
All idle: yes
Done
