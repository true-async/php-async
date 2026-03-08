--TEST--
PDO PgSQL: Persistent connections use sync I/O inside coroutines
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
    global $config;
    $dsn = $config['ENV']['PDOTEST_DSN'];

    // Create a persistent connection inside a coroutine
    $db = new Pdo\Pgsql($dsn, null, null, [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Basic query should work (falls back to sync)
    $stmt = $db->query('SELECT 1 AS n');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "persistent query: " . $row['n'] . "\n";

    // Prepared statement should work
    $stmt = $db->prepare('SELECT :val AS result');
    $stmt->execute(['val' => 'persistent_ok']);
    echo "persistent prepare: " . $stmt->fetchColumn() . "\n";

    // Multiple queries on same persistent connection
    $db->exec('CREATE TABLE IF NOT EXISTS test_persistent_sync (id serial PRIMARY KEY, val text)');
    $db->exec('TRUNCATE test_persistent_sync');
    $db->exec("INSERT INTO test_persistent_sync (val) VALUES ('a'), ('b'), ('c')");

    $stmt = $db->query('SELECT val FROM test_persistent_sync ORDER BY id');
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "persistent rows: " . implode(',', $rows) . "\n";

    $db->exec('DROP TABLE IF EXISTS test_persistent_sync');

    // Non-persistent connection in the same coroutine should still use async
    $db2 = new Pdo\Pgsql($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $stmt = $db2->query('SELECT 42 AS n');
    echo "non-persistent query: " . $stmt->fetch(PDO::FETCH_ASSOC)['n'] . "\n";

    echo "done\n";
});

await($coroutine);
?>
--EXPECT--
persistent query: 1
persistent prepare: persistent_ok
persistent rows: a,b,c
non-persistent query: 42
done
