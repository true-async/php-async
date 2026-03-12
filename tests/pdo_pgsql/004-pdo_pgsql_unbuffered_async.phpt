--TEST--
PDO PgSQL: Unbuffered (lazy fetch) queries work correctly in async context
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

$coroutine = spawn(function() {
    $db = AsyncPDOPgSQLTest::factory();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec('CREATE TABLE IF NOT EXISTS test_async_unbuf (id serial PRIMARY KEY, val text)');
    $db->exec('TRUNCATE test_async_unbuf');

    for ($i = 0; $i < 10; $i++) {
        $db->exec("INSERT INTO test_async_unbuf (val) VALUES ('row_$i')");
    }

    // Test unbuffered fetch (ATTR_PREFETCH = 0)
    echo "=== unbuffered fetch ===\n";
    $stmt = $db->prepare('SELECT val FROM test_async_unbuf ORDER BY id', [
        PDO::ATTR_PREFETCH => 0
    ]);
    $stmt->execute();
    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_COLUMN)) {
        $rows[] = $row;
    }
    echo count($rows) . " rows fetched\n";
    echo "first: " . $rows[0] . ", last: " . $rows[9] . "\n";

    // After unbuffered fetch, a new query on the same connection must work
    echo "=== subsequent query ===\n";
    $stmt2 = $db->query('SELECT COUNT(*) FROM test_async_unbuf');
    echo "count: " . $stmt2->fetchColumn() . "\n";

    // Test switching between unbuffered and buffered
    echo "=== buffered after unbuffered ===\n";
    $stmt3 = $db->prepare('SELECT val FROM test_async_unbuf ORDER BY id LIMIT 3');
    $stmt3->execute();
    $rows = $stmt3->fetchAll(PDO::FETCH_COLUMN);
    echo implode(',', $rows) . "\n";

    $db->exec('DROP TABLE IF EXISTS test_async_unbuf');
    echo "done\n";
});

await($coroutine);
?>
--EXPECT--
=== unbuffered fetch ===
10 rows fetched
first: row_0, last: row_9
=== subsequent query ===
count: 10
=== buffered after unbuffered ===
row_0,row_1,row_2
done
