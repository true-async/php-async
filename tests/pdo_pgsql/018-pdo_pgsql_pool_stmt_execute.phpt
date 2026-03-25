--TEST--
PDO PgSQL Pool: Prepared statement execute works in pool mode (in_transaction on pooled conn)
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

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_exec");
$pdo->exec("CREATE TABLE test_pgsql_pool_exec (id INT PRIMARY KEY, val VARCHAR(50))");

echo "Test 1: Simple prepare+execute in pool mode\n";
$coro1 = spawn(function() use ($pdo) {
    $stmt = $pdo->prepare("INSERT INTO test_pgsql_pool_exec VALUES (?, ?)");
    $stmt->execute([1, 'hello']);
    echo "Insert via prepare+execute: ok\n";

    $stmt2 = $pdo->prepare("SELECT val FROM test_pgsql_pool_exec WHERE id = ?");
    $stmt2->execute([1]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    echo "Value: {$row['val']}\n";
    return true;
});
await($coro1);

echo "Test 2: Prepare+execute with SET command (no result set)\n";
$coro2 = spawn(function() use ($pdo) {
    $stmt = $pdo->prepare('SET search_path TO "public"');
    $stmt->execute();
    echo "SET via prepare+execute: ok\n";
    return true;
});
await($coro2);

echo "Test 3: Prepare+execute inside transaction\n";
$coro3 = spawn(function() use ($pdo) {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO test_pgsql_pool_exec VALUES (?, ?)");
    $stmt->execute([2, 'in_txn']);
    $pdo->commit();

    $stmt2 = $pdo->prepare("SELECT val FROM test_pgsql_pool_exec WHERE id = ?");
    $stmt2->execute([2]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    echo "Value after txn: {$row['val']}\n";
    return true;
});
await($coro3);

echo "Test 4: Multiple coroutines prepare+execute concurrently\n";
$coro4 = spawn(function() use ($pdo) {
    $stmt = $pdo->prepare("INSERT INTO test_pgsql_pool_exec VALUES (?, ?)");
    $stmt->execute([3, 'coro_a']);
    return 'a';
});

$coro5 = spawn(function() use ($pdo) {
    $stmt = $pdo->prepare("INSERT INTO test_pgsql_pool_exec VALUES (?, ?)");
    $stmt->execute([4, 'coro_b']);
    return 'b';
});

await($coro4);
await($coro5);

$stmt = $pdo->query("SELECT val FROM test_pgsql_pool_exec WHERE id IN (3, 4) ORDER BY id");
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
echo "Concurrent results: " . implode(', ', $rows) . "\n";

$pdo->exec("DROP TABLE IF EXISTS test_pgsql_pool_exec");
echo "Done\n";
?>
--EXPECT--
Test 1: Simple prepare+execute in pool mode
Insert via prepare+execute: ok
Value: hello
Test 2: Prepare+execute with SET command (no result set)
SET via prepare+execute: ok
Test 3: Prepare+execute inside transaction
Value after txn: in_txn
Test 4: Multiple coroutines prepare+execute concurrently
Concurrent results: coro_a, coro_b
Done
