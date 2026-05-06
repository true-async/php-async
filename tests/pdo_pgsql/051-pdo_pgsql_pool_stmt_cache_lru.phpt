--TEST--
PDO PgSQL Pool: stmt cache evicts LRU entry and DEALLOCATEs
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
    PDO::ATTR_POOL_STMT_CACHE_SIZE => 2,
]);

Async\await(Async\spawn(function () use ($pdo) {
    $sqls = [
        'SELECT ?::int AS a',
        'SELECT ?::int AS b',
        'SELECT ?::int AS c',
    ];

    foreach ($sqls as $i => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$i + 1]);
        $stmt->fetch();
        unset($stmt);
    }

    /* Switch to emulated prepares so the inspection query bypasses the
     * cache (it would otherwise occupy a slot and evict one of ours). */
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

    $rows = $pdo->query(
        "SELECT statement FROM pg_prepared_statements WHERE statement LIKE 'SELECT \$1::int AS%' ORDER BY statement"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $r) echo $r, "\n";
    echo "count=", count($rows), "\n";
}));

echo "Done\n";
?>
--EXPECT--
SELECT $1::int AS b
SELECT $1::int AS c
count=2
Done
