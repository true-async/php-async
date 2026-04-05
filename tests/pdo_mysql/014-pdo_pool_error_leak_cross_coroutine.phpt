--TEST--
PDO MySQL Pool: error state must not leak from one coroutine to another via pooled connection
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
 * Bug: when a SQL error occurs on a pooled connection, the error state
 * (error_code on both the connection and the template dbh) was not cleared
 * before the connection was released back to the pool. The next coroutine
 * acquiring that connection would see stale error state.
 *
 * Pool max=1 forces both coroutines to use the same physical connection.
 */

$pdo = AsyncPDOMySQLTest::factory(options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 1,
]);

// Coroutine A: trigger a SQL error
$coroA = spawn(function() use ($pdo) {
    $result = $pdo->exec("SELECT 1 FROM nonexistent_table_xyz_12345");
    echo "Coro A errorCode: " . $pdo->errorCode() . "\n";
});

await($coroA);

// Coroutine A is done, connection released back to pool.
// Now coroutine B acquires the same connection.

$coroB = spawn(function() use ($pdo) {
    // Check error state BEFORE any query — should be clean
    $code = $pdo->errorCode();
    $info = $pdo->errorInfo();
    echo "Coro B errorCode before query: " . $code . "\n";
    echo "Coro B SQLSTATE before query: " . $info[0] . "\n";

    // Now run a successful query
    $stmt = $pdo->query("SELECT 1 as val");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Coro B query result: " . $row['val'] . "\n";
    echo "Coro B errorCode after query: " . $pdo->errorCode() . "\n";
});

await($coroB);
echo "Done\n";
?>
--EXPECT--
Coro A errorCode: 42S02
Coro B errorCode before query: 00000
Coro B SQLSTATE before query: 00000
Coro B query result: 1
Coro B errorCode after query: 00000
Done
