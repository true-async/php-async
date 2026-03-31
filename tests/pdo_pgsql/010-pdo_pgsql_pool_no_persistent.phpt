--TEST--
PDO PgSQL Pool: Pool cannot be combined with persistent connections
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

try {
    $dsn = AsyncPDOPgSQLTest::dsn();
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_POOL_ENABLED => true,
        PDO::ATTR_POOL_MIN => 1,
        PDO::ATTR_POOL_MAX => 5,
    ]);
    echo "FAIL: no exception thrown\n";
} catch (PDOException $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}

echo "Done\n";
?>
--EXPECT--
Exception: PDO::ATTR_POOL_ENABLED cannot be used with PDO::ATTR_PERSISTENT
Done
