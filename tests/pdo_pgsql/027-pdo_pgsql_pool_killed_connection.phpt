--TEST--
PDO PgSQL Pool: terminated connection is destroyed, next coroutine gets fresh one
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

/*
 * Scenario: DBA terminates our backend (pg_terminate_backend).
 * Pool must detect conn_broken and destroy instead of reusing.
 */

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
]);

// Separate non-pooled connection for pg_terminate_backend
$killer = AsyncPDOPgSQLTest::factory();
$pool = $pdo->getPool();

// Coroutine A: get connection, kill it externally
$coroA = spawn(function() use ($pdo, $killer) {
    $stmt = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid = $stmt->fetch(PDO::FETCH_ASSOC)['pid'];
    echo "Coro A: pid=$pid\n";
    $stmt = null;

    // Terminate our backend from outside
    $killer->exec("SELECT pg_terminate_backend($pid)");
    usleep(50_000);

    // Next query should fail — connection terminated
    $result = $pdo->exec("SELECT 1");
    $code = $pdo->errorCode();
    echo "Coro A: failed=" . ($code !== '00000' ? 'yes' : 'no') . "\n";
});

await($coroA);

echo "Pool count after A: " . $pool->count() . "\n";

// Coroutine B: pool creates fresh connection
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 42 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B: val=" . $row['val'] . "\n";
});

await($coroB);
echo "Done\n";
?>
--EXPECTF--
Coro A: pid=%d
Coro A: failed=yes
Pool count after A: 0
Coro B: val=42
Done
