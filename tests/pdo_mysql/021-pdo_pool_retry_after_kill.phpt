--TEST--
PDO MySQL Pool: retry query in same coroutine after external KILL gets fresh connection
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

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 2,
]);

$killer = AsyncPDOMySQLTest::factory();

$coro = spawn(function() use ($pdo, $killer) {
    // Get connection, pin with transaction
    $stmt = $pdo->query("SELECT CONNECTION_ID() as id");
    $connId = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
    $stmt = null;
    $pdo->beginTransaction();

    // Kill it externally
    $killer->exec("KILL $connId");
    usleep(50_000);

    // First attempt fails
    $result = $pdo->exec("SELECT 1");
    echo "First attempt errorCode: " . $pdo->errorCode() . "\n";

    // Retry — should get fresh connection
    $stmt = $pdo->query("SELECT 99 as val");
    if ($stmt !== false) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Retry result: " . $row['val'] . "\n";
    } else {
        echo "Retry failed: " . $pdo->errorCode() . "\n";
    }
});

await($coro);
echo "Done\n";
?>
--EXPECT--
First attempt errorCode: HY000
Retry result: 99
Done
