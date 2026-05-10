--TEST--
PDO PgSQL Pool: one of concurrent connections terminated, other unaffected
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
use function Async\delay;

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 2, extra: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
]);

$killer = AsyncPDOPgSQLTest::factory();
$pool = $pdo->getPool();
$ready = new Channel(0);

// Coroutine A: gets terminated while blocked on pg_sleep
$coroA = spawn(function() use ($pdo, $ready) {
    $stmt = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid = $stmt->fetch(PDO::FETCH_ASSOC)['pid'];
    $stmt = null;
    $ready->send($pid);

    $pdo->exec("SELECT pg_sleep(5)");
    return "A:error=" . $pdo->errorCode();
});

// Coroutine B: normal
$coroB = spawn(function() use ($pdo) {
    $stmt = $pdo->query("SELECT 'ok' as status");
    return "B:" . $stmt->fetch(PDO::FETCH_ASSOC)['status'];
});

// Main: get pid, let coro A enter pg_sleep, then terminate
$pid = $ready->recv();
delay(50);
$killer->exec("SELECT pg_terminate_backend($pid)");

// Collect results into an order-independent array so the echo order
// doesn't depend on which coroutine resolves first.
$results = [await($coroA), await($coroB)];
sort($results);
foreach ($results as $r) {
    echo $r . "\n";
}

// Pool cleanup after a terminated connection is asynchronous. Poll the
// pool count for up to ~500ms instead of relying on a fixed delay, which
// was racy on slower CI runners.
for ($i = 0; $i < 50 && $pool->count() > 1; $i++) {
    delay(10);
}
echo "Pool count: " . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECTF--
A:error=%s
B:ok
Pool count: 1
Done
