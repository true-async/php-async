--TEST--
PDO MySQL Pool: one of concurrent connections killed, others unaffected
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

use Async\Channel;
use function Async\spawn;
use function Async\await;
use function Async\suspend;

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 3,
]);

$killer = AsyncPDOMySQLTest::factory();
$pool = $pdo->getPool();
$ready = new Channel(1);

// Coroutine A: blocks on SLEEP, gets killed mid-query
$coroA = spawn(function() use ($pdo, $ready) {
    $stmt = $pdo->query("SELECT CONNECTION_ID() as id");
    $connId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    $stmt = null;
    $ready->send($connId);

    // Block here — KILL will interrupt this
    $pdo->exec("SELECT SLEEP(5)");
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

// Main: wait for A to start sleeping, then kill
$connId = $ready->recv();
$killer->exec("KILL $connId");

echo await($coroA) . "\n";
echo await($coroB) . "\n";
echo await($coroC) . "\n";
suspend();
echo "Pool count: " . $pool->count() . "\n";
echo "Done\n";
?>
--EXPECT--
A:error=HY000
B:ok
C:ok
Pool count: 2
Done
