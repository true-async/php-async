--TEST--
PDO PgSQL Pool: Two statements in same coroutine share same connection
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

$pdo = AsyncPDOPgSQLTest::poolFactory();

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_shared");
$pdo->exec("CREATE TABLE test_pgsql_pool_shared (id SERIAL PRIMARY KEY, val VARCHAR(50))");

$coro = spawn(function() use ($pdo) {
    $stmt1 = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid1 = $stmt1->fetch()['pid'];

    $stmt2 = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid2 = $stmt2->fetch()['pid'];

    echo "Same connection: " . ($pid1 === $pid2 ? "yes" : "no") . "\n";

    $stmt3 = $pdo->prepare("INSERT INTO test_pgsql_pool_shared (val) VALUES (?)");
    $stmt3->execute(['hello']);

    $stmt4 = $pdo->prepare("SELECT val FROM test_pgsql_pool_shared WHERE id = ?");
    $stmt4->execute([1]);
    $row = $stmt4->fetch();
    echo "Value: {$row['val']}\n";

    return true;
});

await($coro);
$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_shared");
echo "Done\n";
?>
--EXPECT--
Same connection: yes
Value: hello
Done
