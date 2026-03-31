--TEST--
PDO PgSQL Pool: Concurrent INSERT/UPDATE from multiple coroutines
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
use function Async\await_all_or_fail;

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 5);

// Setup test table
$pdo->exec("DROP TABLE IF EXISTS test_concurrent_write");
$pdo->exec("CREATE TABLE test_concurrent_write (id SERIAL PRIMARY KEY, val INT)");

$count = 30;
$coroutines = [];

for ($i = 0; $i < $count; $i++) {
    $coroutines[] = spawn(function () use ($pdo, $i) {
        $stmt = $pdo->prepare("INSERT INTO test_concurrent_write (val) VALUES (?)");
        $stmt->execute([$i + 1]);
    });
}

await_all_or_fail($coroutines);

// Verify all rows were inserted
$stmt = $pdo->query("SELECT COUNT(*) as cnt, SUM(val) as total FROM test_concurrent_write");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$expectedSum = ($count * ($count + 1)) / 2;

echo "Rows: " . $row['cnt'] . "\n";
echo "Sum correct: " . ((int)$row['total'] === $expectedSum ? "yes" : "no") . "\n";

// Cleanup
$pdo->exec("DROP TABLE test_concurrent_write");
echo "Done\n";
?>
--EXPECT--
Rows: 30
Sum correct: yes
Done
