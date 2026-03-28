--TEST--
PDO PgSQL Pool: High concurrency — many coroutines sharing pool
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
$pool = $pdo->getPool();

$coroutines = [];
$count = 20;

for ($i = 0; $i < $count; $i++) {
    $coroutines[] = spawn(function () use ($pdo, $i) {
        $stmt = $pdo->query("SELECT " . ($i + 1) . " as val");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $row['val'];
    });
}

$results = await_all_or_fail($coroutines);

$sum = array_sum($results);
$expected = ($count * ($count + 1)) / 2;

echo "Coroutines: " . $count . "\n";
echo "Sum correct: " . ($sum === $expected ? "yes" : "no ($sum != $expected)") . "\n";
echo "Max pool count: " . $pool->count() . "\n";
echo "Pool count <= max: " . ($pool->count() <= 5 ? "yes" : "no") . "\n";
echo "Done\n";
?>
--EXPECT--
Coroutines: 20
Sum correct: yes
Max pool count: 5
Pool count <= max: yes
Done
