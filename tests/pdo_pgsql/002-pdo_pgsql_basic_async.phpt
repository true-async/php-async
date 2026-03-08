--TEST--
PDO PgSQL: Basic async queries with concurrent coroutines
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

    $db->exec('CREATE TABLE IF NOT EXISTS test_async_basic (id serial PRIMARY KEY, val text)');
    $db->exec('TRUNCATE test_async_basic');

    // Test INSERT + SELECT
    $db->exec("INSERT INTO test_async_basic (val) VALUES ('hello'), ('world'), ('async')");

    $stmt = $db->query('SELECT val FROM test_async_basic ORDER BY id');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo implode(',', $rows) . "\n";

    // Test prepared statements
    $stmt = $db->prepare('SELECT val FROM test_async_basic WHERE id = :id');
    $stmt->execute(['id' => 2]);
    echo $stmt->fetchColumn() . "\n";

    // Test concurrent coroutines with separate connections
    $c1 = spawn(function() {
        $db = AsyncPDOPgSQLTest::factory();
        $stmt = $db->query("SELECT 'coroutine1' AS result");
        return $stmt->fetchColumn();
    });

    $c2 = spawn(function() {
        $db = AsyncPDOPgSQLTest::factory();
        $stmt = $db->query("SELECT 'coroutine2' AS result");
        return $stmt->fetchColumn();
    });

    echo await($c1) . "\n";
    echo await($c2) . "\n";

    $db->exec('DROP TABLE IF EXISTS test_async_basic');
    echo "done\n";
});

await($coroutine);
?>
--EXPECT--
hello,world,async
world
coroutine1
coroutine2
done
