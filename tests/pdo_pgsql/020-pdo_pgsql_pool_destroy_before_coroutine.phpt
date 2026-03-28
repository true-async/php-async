--TEST--
PDO PgSQL Pool: PDO object destroyed before coroutine finishes (no crash)
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
use function Async\suspend;

$coro = spawn(function () {
    $pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 3);

    $inner = spawn(function () use ($pdo) {
        // Acquire a connection
        $stmt = $pdo->query("SELECT 'hello' as msg");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Inner: " . $row['msg'] . "\n";

        // Suspend — PDO will be destroyed while we're suspended
        suspend();

        echo "Inner resumed after PDO destroyed\n";
    });

    // Let inner coroutine start and suspend
    suspend();

    // Destroy PDO — pool_destroy should invalidate bindings
    unset($pdo);
    echo "PDO destroyed\n";

    // Resume inner — its binding callback should be a no-op
    await($inner);
});

await($coro);
echo "Done\n";
?>
--EXPECT--
PDO destroyed
Inner: hello
Inner resumed after PDO destroyed
Done
