--TEST--
PDO PgSQL Pool: stmt cache survives coroutine churn on the same physical conn
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

/* Single physical conn — all coroutines share it serially. */
$pdo = AsyncPDOPgSQLTest::poolFactory(poolMax: 1, extra: [
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 16,
]);

$sql = 'SELECT ?::int + 1 AS r';

$tasks = [];
for ($i = 0; $i < 8; $i++) {
    $tasks[] = Async\spawn(function () use ($pdo, $sql, $i) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i]);
        return (int)$stmt->fetchColumn();
    });
}

$sum = 0;
foreach ($tasks as $t) {
    $sum += Async\await($t);
}
echo "sum=", $sum, "\n";

Async\await(Async\spawn(function () use ($pdo) {
    /* All 8 prepares should have collapsed to a single cached server-side stmt. */
    $count = (int)$pdo->query(
        "SELECT count(*) FROM pg_prepared_statements WHERE statement LIKE 'SELECT \$1::int + 1%'"
    )->fetchColumn();
    echo "prepared_count=", $count, "\n";
}));

echo "Done\n";
?>
--EXPECT--
sum=36
prepared_count=1
Done
