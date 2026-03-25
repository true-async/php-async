--TEST--
PDO PgSQL Pool: Pool creation with ATTR_POOL_ENABLED
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

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 5);

$pool = $pdo->getPool();

if ($pool === null) {
    echo "ERROR: getPool() returned null\n";
} else {
    echo "Pool created: " . get_class($pool) . "\n";
    echo "Pool is Async\\Pool: " . ($pool instanceof \Async\Pool ? "yes" : "no") . "\n";
}

echo "Done\n";
?>
--EXPECT--
Pool created: Async\Pool
Pool is Async\Pool: yes
Done
