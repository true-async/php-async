--TEST--
PDO PgSQL Pool: stmt cache reuses server-side prepared name on hit
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
]);

Async\await(Async\spawn(function () use ($pdo) {
    $sql = 'SELECT ?::int + ?::int AS r';

    for ($i = 0; $i < 5; $i++) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i, 1]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        var_dump($row['r']);
        unset($stmt);
    }

    /* Filter by SQL text pattern so the inspection query (itself prepared) doesn't pollute the count. */
    $count = (int)$pdo->query(
        "SELECT count(*) FROM pg_prepared_statements WHERE statement LIKE 'SELECT \$1::int + \$2::int%'"
    )->fetchColumn();
    echo "prepared_count=", $count, "\n";
}));

echo "Done\n";
?>
--EXPECT--
int(1)
int(2)
int(3)
int(4)
int(5)
prepared_count=1
Done
