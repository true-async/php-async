--TEST--
PDO MySQL Pool: statement survives connection kill, cleanup doesn't crash
--EXTENSIONS--
pdo_mysql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';
AsyncPDOMySQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_mysql_test.inc';

use function Async\spawn;
use function Async\await;

/*
 * Coroutine holds an active $stmt (refcount on conn).
 * Connection is killed externally.
 * Verify: fetch fails gracefully, stmt destruction doesn't crash,
 * broken conn is destroyed by pool, next coroutine works.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 1,
]);

$killer = AsyncPDOMySQLTest::factory();
$pool = $pdo->getPool();

$coro = spawn(function() use ($pdo, $killer) {
    // Create statement — holds refcount on pooled conn
    $stmt = $pdo->query("SELECT CONNECTION_ID() as id");
    $connId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    echo "Got conn: $connId\n";

    // Kill connection while stmt is alive
    $killer->exec("KILL $connId");
    usleep(50_000);

    // Try to use the dead connection via new query
    $stmt2 = $pdo->query("SELECT 1");
    echo "New query on dead conn: " . ($stmt2 === false ? "failed" : "ok") . "\n";

    // Destroy statements — should not crash
    $stmt = null;
    $stmt2 = null;
    echo "Stmts destroyed safely\n";
});

await($coro);

echo "Pool count: " . $pool->count() . "\n";

// Next coroutine gets fresh connection
$coro2 = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 42 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Next coro: val=" . $row['val'] . "\n";
});

await($coro2);
echo "Done\n";
?>
--EXPECTF--
Got conn: %d
New query on dead conn: failed
Stmts destroyed safely
Pool count: 0
Next coro: val=42
Done
