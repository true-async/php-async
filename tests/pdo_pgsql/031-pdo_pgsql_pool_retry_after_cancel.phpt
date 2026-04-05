--TEST--
PDO PgSQL Pool: retry query in same coroutine after cancel gets fresh connection
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
use Async\AsyncCancellation;
use function Async\spawn;
use function Async\await;

/*
 * Coroutine catches cancel, then retries query on the same $pdo.
 * acquire_conn must detect conn_broken and give a fresh connection.
 */

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 2);

$ready = new Channel(1);

$coro = spawn(function() use ($pdo, $ready) {
    // First query — will be cancelled
    try {
        $ready->send(true);
        $pdo->query("SELECT pg_sleep(5)");
    } catch (AsyncCancellation $e) {
        echo "Caught cancel\n";
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
--EXPECT--
Caught cancel
Retry result: 42
Done
