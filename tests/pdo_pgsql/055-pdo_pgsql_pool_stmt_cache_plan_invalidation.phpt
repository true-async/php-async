--TEST--
PDO PgSQL Pool: stmt cache transparently re-prepares after server-side plan invalidation
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
    $tbl = 'pdo_stmtcache_test_' . getmypid();
    $pdo->exec("CREATE TABLE {$tbl} (id int PRIMARY KEY, val int)");
    $pdo->exec("INSERT INTO {$tbl} VALUES (1, 100), (2, 200), (3, 300)");

    $sql = "SELECT * FROM {$tbl} WHERE id = ?";

    /* Warm the cache: first prepare/execute populates the server-side stmt
     * and the PDO-pool cache entry. */
    $stmt = $pdo->prepare($sql);
    $stmt->execute([1]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "before: id=", $r['id'], " val=", $r['val'], "\n";
    unset($stmt);

    /* Trigger plan invalidation: changing a referenced column type forces
     * PostgreSQL to retire the cached plan. The next EXECUTE on the
     * server-side prepared stmt would normally fail with SQLSTATE 0A000
     * "cached plan must not change result type". */
    $pdo->exec("ALTER TABLE {$tbl} ALTER COLUMN val TYPE bigint");

    /* Same SQL, same coroutine, same physical conn — but the cached plan is
     * stale. The pool should evict + DEALLOCATE + re-PQprepare transparently;
     * user code never sees the 0A000. */
    $stmt = $pdo->prepare($sql);
    $stmt->execute([2]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "after_alter: id=", $r['id'], " val=", $r['val'], "\n";
    unset($stmt);

    /* Subsequent prepare must hit the freshly-rebuilt cache entry — only one
     * server-side prepared stmt for our SQL, even after the invalidation. */
    $stmt = $pdo->prepare($sql);
    $stmt->execute([3]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "third: id=", $r['id'], " val=", $r['val'], "\n";
    unset($stmt);

    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $count = (int)$pdo->query(
        "SELECT count(*) FROM pg_prepared_statements WHERE statement LIKE 'SELECT * FROM {$tbl}%'"
    )->fetchColumn();
    echo "cached_after=", $count, "\n";

    $pdo->exec("DROP TABLE {$tbl}");
}));

echo "Done\n";
?>
--EXPECT--
before: id=1 val=100
after_alter: id=2 val=200
third: id=3 val=300
cached_after=1
Done
