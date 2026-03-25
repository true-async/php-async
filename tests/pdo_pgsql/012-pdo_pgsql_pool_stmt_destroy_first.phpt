--TEST--
PDO PgSQL Pool: Destroying first statement does not break second statement
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

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_destroy");
$pdo->exec("CREATE TABLE test_pgsql_pool_destroy (id SERIAL PRIMARY KEY, val VARCHAR(50))");

$coro = spawn(function() use ($pdo) {
    $stmt1 = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid1 = $stmt1->fetch()['pid'];

    $stmt2 = $pdo->prepare("INSERT INTO test_pgsql_pool_destroy (val) VALUES (?)");

    unset($stmt1);

    $stmt2->execute(['after_unset']);
    echo "Insert after unset(stmt1): ok\n";

    $stmt3 = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid3 = $stmt3->fetch()['pid'];
    echo "Same connection after unset: " . ($pid1 === $pid3 ? "yes" : "no") . "\n";

    $stmt4 = $pdo->query("SELECT val FROM test_pgsql_pool_destroy");
    $row = $stmt4->fetch();
    echo "Value: {$row['val']}\n";

    return true;
});

await($coro);
$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_destroy");
echo "Done\n";
?>
--EXPECT--
Insert after unset(stmt1): ok
Same connection after unset: yes
Value: after_unset
Done
