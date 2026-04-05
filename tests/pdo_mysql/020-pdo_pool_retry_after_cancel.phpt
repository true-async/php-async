--TEST--
PDO MySQL Pool: retry query in same coroutine after cancel gets fresh connection
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
 * Coroutine catches cancel, then retries query on the same $pdo.
 * acquire_conn must detect conn_broken and give a fresh connection.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 2,
]);

$ready = new Channel(1);

$coro = spawn(function() use ($pdo, $ready) {
    // First query — will be cancelled.
    // MySQL throws PDOException (2006 gone away), not AsyncCancellation,
    // because the driver detects the broken connection first.
    try {
        $ready->send(true);
        $pdo->query("SELECT SLEEP(5)");
    } catch (\Throwable $e) {
        echo "Caught: " . get_class($e) . "\n";
    }

    // Retry — should get a fresh connection automatically
    $stmt = $pdo->query("SELECT 42 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Retry result: " . $row['val'] . "\n";
});

$ready->recv();
$coro->cancel(new AsyncCancellation("test"));

try { await($coro); } catch (AsyncCancellation $e) {}

echo "Done\n";
?>
--EXPECTF--
%ACaught: %s
Retry result: 42
Done
