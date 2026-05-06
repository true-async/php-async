--TEST--
PDO PgSQL Pool: stmt cache bypassed when ATTR_EMULATE_PREPARES => true
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

$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 16,
    PDO::ATTR_EMULATE_PREPARES => true,
]);

Async\await(Async\spawn(function () use ($pdo) {
    $sql = 'SELECT ?::int AS r';

    for ($i = 0; $i < 3; $i++) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i]);
        $stmt->fetch();
        unset($stmt);
    }

    /* Emulated prepares never call PQprepare — none of our SQL is server-prepared. */
    $count = (int)$pdo->query(
        "SELECT count(*) FROM pg_prepared_statements WHERE statement LIKE 'SELECT %::int AS r%'"
    )->fetchColumn();
    echo "prepared_count=", $count, "\n";
}));

echo "Done\n";
?>
--EXPECT--
prepared_count=0
Done
