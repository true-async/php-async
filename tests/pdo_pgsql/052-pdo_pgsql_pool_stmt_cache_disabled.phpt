--TEST--
PDO PgSQL Pool: stmt cache disabled (size=0) — every prepare allocates a new server-side stmt
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

/* Default: cache disabled. */
$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 1);

Async\await(Async\spawn(function () use ($pdo) {
    $sql = 'SELECT ?::int AS r';

    /* Three identical prepares — without a cache each one issues its own
     * PQprepare with a fresh name and deallocates on stmt dtor. */
    for ($i = 0; $i < 3; $i++) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i]);
        $stmt->fetch();
        unset($stmt);
    }

    /* After all stmts are destroyed and DEALLOCATE'd, none of our SQL remains. */
    $count = (int)$pdo->query(
        "SELECT count(*) FROM pg_prepared_statements WHERE statement LIKE 'SELECT \$1::int AS r%'"
    )->fetchColumn();
    echo "remaining_after_dtor=", $count, "\n";
}));

echo "Done\n";
?>
--EXPECT--
remaining_after_dtor=0
Done
