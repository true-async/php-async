--TEST--
PDO PgSQL Pool: one of concurrent connections terminated, others unaffected
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

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 3, extra: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
]);

$killer = AsyncPDOPgSQLTest::factory();
$pool = $pdo->getPool();
$ready = new Channel(1);

// Coroutine A: blocks on pg_sleep, gets terminated mid-query
$coroA = spawn(function() use ($pdo, $ready) {
    $stmt = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid = $stmt->fetch(PDO::FETCH_ASSOC)['pid'];
    $stmt = null;
    $ready->send($pid);

    // Block here — terminate will interrupt this
    $pdo->exec("SELECT pg_sleep(5)");
    return "A:error=" . $pdo->errorCode();
});

// Coroutine B: normal
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 'ok' as status");
    return "B:" . $stmt->fetch(PDO::FETCH_ASSOC)['status'];
});

// Coroutine C: normal
$coroC = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 'ok' as status");
    return "C:" . $stmt->fetch(PDO::FETCH_ASSOC)['status'];
});

// Main: wait for A to start sleeping, then terminate
$pid = $ready->recv();
$killer->exec("SELECT pg_terminate_backend($pid)");

echo await($coroA) . "\n";
echo await($coroB) . "\n";
echo await($coroC) . "\n";
suspend();
echo "Pool count: " . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECTF--
A:error=%s
B:ok
C:ok
Pool count: 2
Done
