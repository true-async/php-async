--TEST--
PDO PgSQL Pool: Different coroutines get different connections, each with own refcount
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

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_cross");
$pdo->exec("CREATE TABLE test_pgsql_pool_cross (id SERIAL PRIMARY KEY, val VARCHAR(50))");

$results = [];

$coro1 = spawn(function() use ($pdo, &$results) {
    $stmtA = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid = $stmtA->fetch()['pid'];

    $stmtB = $pdo->prepare("INSERT INTO test_pgsql_pool_cross (val) VALUES (?)");

    unset($stmtA);

    $stmtB->execute(['coro1']);

    $stmtC = $pdo->query("SELECT pg_backend_pid() as pid");
    $check = $stmtC->fetch()['pid'];
    $results['coro1'] = ($pid === $check ? "yes" : "no");

    return $pid;
});

$coro2 = spawn(function() use ($pdo, &$results) {
    $stmtA = $pdo->query("SELECT pg_backend_pid() as pid");
    $pid = $stmtA->fetch()['pid'];

    $stmtB = $pdo->prepare("INSERT INTO test_pgsql_pool_cross (val) VALUES (?)");

    unset($stmtA);

    $stmtB->execute(['coro2']);

    $stmtC = $pdo->query("SELECT pg_backend_pid() as pid");
    $check = $stmtC->fetch()['pid'];
    $results['coro2'] = ($pid === $check ? "yes" : "no");

    return $pid;
});

$pid1 = await($coro1);
$pid2 = await($coro2);

ksort($results);
foreach ($results as $name => $same) {
    echo "$name same conn: $same\n";
}
echo "Different connections across coroutines: " . ($pid1 !== $pid2 ? "yes" : "no") . "\n";

$stmt = $pdo->query("SELECT val FROM test_pgsql_pool_cross ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
sort($rows);
echo "Rows: " . implode(', ', $rows) . "\n";

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_cross");
echo "Done\n";
?>
--EXPECT--
coro1 same conn: yes
coro2 same conn: yes
Different connections across coroutines: yes
Rows: coro1, coro2
Done
