--TEST--
PDO PgSQL: a persistent connection opened in the main flow works inside a coroutine; one cannot be opened there
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

$dsn = AsyncPDOPgSQLTest::dsn();

// Opened in the main flow: its socket does sync I/O, so it outlives the request.
$db = new Pdo\Pgsql($dsn, null, null, [
    PDO::ATTR_PERSISTENT => true,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

await(spawn(function () use ($db, $dsn) {
    $stmt = $db->query('SELECT 1 AS n');
    echo "persistent query: " . $stmt->fetch(PDO::FETCH_ASSOC)['n'] . "\n";

    $stmt = $db->prepare('SELECT :val AS result');
    $stmt->execute(['val' => 'persistent_ok']);
    echo "persistent prepare: " . $stmt->fetchColumn() . "\n";

    $db->exec('CREATE TABLE IF NOT EXISTS test_persistent_sync (id serial PRIMARY KEY, val text)');
    $db->exec('TRUNCATE test_persistent_sync');
    $db->exec("INSERT INTO test_persistent_sync (val) VALUES ('a'), ('b'), ('c')");

    $stmt = $db->query('SELECT val FROM test_persistent_sync ORDER BY id');
    echo "persistent rows: " . implode(',', $stmt->fetchAll(PDO::FETCH_COLUMN)) . "\n";

    $db->exec('DROP TABLE IF EXISTS test_persistent_sync');

    // Opening one inside a coroutine is refused: a coroutine uses a pool.
    try {
        new Pdo\Pgsql($dsn, null, null, [PDO::ATTR_PERSISTENT => true]);
        echo "FAIL: no exception thrown\n";
    } catch (PDOException $e) {
        echo "persistent in coroutine: " . $e->getMessage() . "\n";
    }

    $db2 = new Pdo\Pgsql($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "non-persistent query: " . $db2->query('SELECT 42 AS n')->fetch(PDO::FETCH_ASSOC)['n'] . "\n";
}));

echo "done\n";
?>
--EXPECT--
persistent query: 1
persistent prepare: persistent_ok
persistent rows: a,b,c
persistent in coroutine: PDO::ATTR_PERSISTENT cannot be used inside a coroutine; use PDO::ATTR_POOL_ENABLED
non-persistent query: 42
done
