--TEST--
PDO PgSQL Pool: Close pool with active healthcheck timer (no crash)
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
use function Async\delay;

// Create pool with healthcheck enabled (interval=100ms)
$pdo = new PDO(AsyncPDOPgSQLTest::dsn(), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 2,
    PDO::ATTR_POOL_MAX => 5,
    PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => 100,
]);

$pool = $pdo->getPool();
echo "Pool created with healthcheck\n";
echo "Initial count: " . $pool->count() . "\n";

$coro = spawn(function () use ($pdo, $pool) {
    // Use the pool
    $stmt = $pdo->query("SELECT 'test' as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Query: " . $row['val'] . "\n";
    unset($stmt);

    // Wait for healthcheck to run at least once
    delay(150);

    echo "After healthcheck delay\n";
    echo "Pool count: " . $pool->count() . "\n";
});

await($coro);

// Destroy PDO — should close pool and clean up healthcheck timer without crash
unset($pdo);
echo "PDO destroyed (no crash)\n";
echo "Done\n";
?>
--EXPECT--
Pool created with healthcheck
Initial count: 0
Query: test
After healthcheck delay
Pool count: 1
PDO destroyed (no crash)
Done
