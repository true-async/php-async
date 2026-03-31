--TEST--
PDO PgSQL Pool: Binding reuse — same coroutine does multiple acquire/release cycles
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

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 3);
$pool = $pdo->getPool();

$coro = spawn(function () use ($pdo, $pool) {
    // First acquire/release cycle
    $stmt = $pdo->query("SELECT 1 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Cycle 1: " . $row['val'] . "\n";
    unset($stmt); // triggers release

    $idle1 = $pool->idleCount();
    echo "Idle after cycle 1: " . $idle1 . "\n";

    // Second acquire/release cycle — should reuse binding, no new allocation
    $stmt = $pdo->query("SELECT 2 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Cycle 2: " . $row['val'] . "\n";
    unset($stmt);

    // Third cycle
    $stmt = $pdo->query("SELECT 3 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Cycle 3: " . $row['val'] . "\n";
    unset($stmt);
});

await($coro);
echo "Pool count: " . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECT--
Cycle 1: 1
Idle after cycle 1: 1
Cycle 2: 2
Cycle 3: 3
Pool count: 1
Done
