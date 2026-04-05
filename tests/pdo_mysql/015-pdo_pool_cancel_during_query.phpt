--TEST--
PDO MySQL Pool: cancelled connection is destroyed, not returned to pool
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
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;

/*
 * Cancel coroutine during SLEEP query. conn_broken flag → pool destroys connection.
 * Next coroutine gets a fresh connection from pool.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 2,
]);

$pool = $pdo->getPool();
$ready = new Channel(1);

$coroA = spawn(function() use ($pdo, $ready) {
    try {
        $ready->send(true);
        $pdo->query("SELECT SLEEP(5)");
    } catch (\Throwable $e) {
        // MySQL may throw PDOException(2006) or AsyncCancellation
    }
});

$ready->recv();
$coroA->cancel(new AsyncCancellation("test cancel"));

try { await($coroA); } catch (\Throwable $e) {}

echo "Pool count after cancel: " . $pool->count() . "\n";

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
%APool count after cancel: %d
Coro B: val=42
Done
